<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Entity\DomainRenewal;
use Drupal\commerce_domains_owner\Entity\DomainRenewalInterface;
use Drupal\commerce_domains_owner\Expenses;
use Drupal\commerce_domains_owner\RenewalHistory;
use Drupal\commerce_domains_owner\RenewalImporter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the renewals paid: importing them, and what they change.
 */
#[CoversClass(RenewalHistory::class)]
#[CoversClass(RenewalImporter::class)]
#[CoversClass(DomainRenewal::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RenewalHistoryTest extends OwnerKernelTestBase {

  /**
   * Returns a Unix time for a date, at noon UTC.
   */
  protected static function date(string $date): int {
    return (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   * Records a renewal.
   */
  protected function renewal(ProductInterface $product, string $date, ?Price $amount, int $years = 1, bool $estimated = FALSE): DomainRenewalInterface {
    $renewal = DomainRenewal::create([
      'product' => $product->id(),
      'paid' => self::date($date),
      'years' => $years,
      'amount' => $amount,
      'estimated' => $estimated,
    ]);
    $renewal->save();
    return $renewal;
  }

  /**
   * Tests that recorded renewals count, and only the rest is estimated.
   */
  public function testCosts(): void {
    $costs = $this->container->get(Costs::class);
    $held = $this->createDomain('Held.com', 1000, [
      'domain_purchase_price' => new Price('100', 'USD'),
      'domain_purchase_date' => self::date('2022-03-01'),
      'domain_renewal_cost' => new Price('20', 'USD'),
      'domain_expires' => self::date('2027-03-01'),
    ]);
    $now = self::date('2026-11-01');

    // Anniversaries 2023 to 2026, none recorded: all estimated.
    $payments = $costs->renewalPayments($held, $now);
    $this->assertCount(4, $payments);
    $this->assertTrue($payments[3]['estimated']);
    $this->assertEquals(new Price('80', 'USD'), $costs->renewalsTotal($payments));

    // Two recorded, one of them 2 years: one estimate left, the oldest.
    $this->renewal($held, '2024-02-20', new Price('21.50', 'USD'), 2);
    $this->renewal($held, '2026-02-20', new Price('10.88', 'USD'));
    // Before the purchase: someone else's, and it doesn't count.
    $this->renewal($held, '2021-02-20', new Price('999', 'USD'));
    $payments = $costs->renewalPayments($held, $now);
    $this->assertSame(['2023-03-01', '2024-02-20', '2026-02-20'], array_map(fn(array $payment) => gmdate('Y-m-d', $payment['time']), $payments));
    $this->assertSame([TRUE, FALSE, FALSE], array_column($payments, 'estimated'));
    $this->assertEquals(new Price('52.38', 'USD'), $costs->renewalsTotal($payments));

    // By year: paid when it was paid, the years it paid for counted.
    $expenses = $this->container->get(Expenses::class);
    $by_year = $expenses->byYear($now)['years'];
    $this->assertSame(2, $by_year[2024]['renewals']);
    $this->assertSame(0, $by_year[2024]['estimated']);
    $this->assertEquals(['USD' => new Price('21.50', 'USD')], $by_year[2024]['renewal_costs']);
    $this->assertSame(1, $by_year[2023]['estimated']);
    $invested = $expenses->invested($held, $now);
    $this->assertSame(4, $invested['renewals']);
    $this->assertEquals(new Price('152.38', 'USD'), $invested['total']);

    // Its own renewal cost goes first; without one, the latest charge a year.
    $this->assertEquals(new Price('20', 'USD'), $costs->renewalCost($held));
    $held->set('domain_renewal_cost', NULL)->save();
    $this->assertEquals(new Price('10.88', 'USD'), $costs->renewalCost($held));
    $this->assertFalse($costs->renewsAtDefault($held));
    $this->renewal($held, '2026-10-01', new Price('30', 'USD'), 2);
    $this->assertEquals(new Price('15', 'USD'), $costs->renewalCost($held), 'Per year.');
    // An estimate knows nothing new.
    $this->renewal($held, '2026-10-20', new Price('99', 'USD'), 1, TRUE);
    $this->assertEquals(new Price('15', 'USD'), $costs->renewalCost($held));

    // A sale counts the renewals up to it, in its profit.
    $sold = $this->createDomain('Sold.com', 1000, [
      'domain_purchase_price' => new Price('100', 'USD'),
      'domain_purchase_date' => self::date('2024-01-01'),
      'domain_expires' => self::date('2027-06-01'),
      'domain_sale_price' => new Price('500', 'USD'),
      'domain_sold' => self::date('2026-07-01'),
    ]);
    $this->renewal($sold, '2025-05-20', new Price('12', 'USD'));
    $this->renewal($sold, '2026-08-01', new Price('12', 'USD'));
    $profit = $costs->profit($sold);
    // 2024-06, 2025-06 and 2026-06 fell due: one recorded before the sale,
    // the other two at the yearly cost, its latest charge.
    $this->assertSame(3, $profit->renewals);
    $this->assertEquals(new Price('36', 'USD'), $profit->renewalsPaid);
    $this->assertEquals(new Price('364', 'USD'), $profit->profit);

    // Amounts in two currencies, without a rate between them: no sum.
    $this->renewal($sold, '2026-01-20', new Price('10', 'EUR'));
    $profit = $costs->profit($sold);
    $this->assertNull($profit->renewalsPaid);
    $this->assertNull($profit->profit);
    $this->assertNotNull($profit->problem);
  }

  /**
   * Tests importing Dynadot's order history, and importing it again.
   */
  public function testImportDynadot(): void {
    $this->createProvider('dynadot');
    $renewed = $this->createDomain('Renewed.com');
    $moved = $this->createDomain('Moved.io');
    $closeout = $this->createDomain('Closeout.com');
    $euro = $this->createDomain('Euro.com');
    $header = 'Bill From,Bill To,Date Submitted,invoice #,Order ID,Currency,Cost,Paid,Status,Payment Type,Item Detail,Item,TLD';
    $csv = implode("\n", [
      $header,
      'Dynadot  INC,Vallic d.o.o.,2026/10/26,1,11,US Dollar USD ($) ,10.88,10.88,Completed,Wise,Domain Renewal (1 Year),"renewed.com",com',
      'Dynadot  INC,Vallic d.o.o.,2026/03/01,2,12,US Dollar USD ($) ,49.99,49.99,Completed,Wise,domain transfer (2 Year),"moved.io",io',
      'Dynadot  INC,Vallic d.o.o.,2026/02/01,3,13,Euro EUR (€) ,9.59,9.59,Completed,Wise,Domain Renewal (1 Year),"euro.com",com',
      // A closeout has no detail: it is the purchase, not a renewal.
      'Dynadot  INC,Vallic d.o.o.,2025/10/04,4,14,US Dollar USD ($) ,15.88,15.88,Completed,Wise,,"closeout.com",com',
      'Dynadot  INC,Vallic d.o.o.,2026/01/01,5,15,US Dollar USD ($) ,0.00,0,Completed,Wise,domain transfer away,"closeout.com",com',
      'Dynadot  INC,Vallic d.o.o.,2026/01/02,6,16,US Dollar USD ($) ,10.00,0,Cancelled,Wise,Domain Renewal (1 Year),"closeout.com",com',
      'Dynadot  INC,Vallic d.o.o.,2026/01/03,7,17,US Dollar USD ($) ,8.99,8.99,Completed,Wise,domain registration (1 Year),"closeout.com",com',
      'Dynadot  INC,Vallic d.o.o.,2026/01/04,8,18,US Dollar USD ($) ,-0.73,-0.73,Completed,Wise,Payment Discount,"payment discount",',
      'Dynadot  INC,Vallic d.o.o.,2026/01/05,9,19,US Dollar USD ($) ,10.88,10.88,Completed,Wise,Domain Renewal (1 Year),"elsewhere.com",com',
    ]) . "\n";

    $importer = $this->container->get(RenewalImporter::class);
    $plan = $importer->plan(RenewalImporter::parse($csv));
    $this->assertSame(['new' => 3, 'known' => 0, 'passed' => 5, 'problems' => 1], RenewalImporter::summary($plan));
    $this->assertSame('Not in the catalog.', $plan[10]['problem']);
    $this->assertSame(3, $importer->apply($plan));

    $history = $this->container->get(RenewalHistory::class);
    $this->assertSame([], $history->forProduct($closeout));
    $renewal = $history->forProduct($renewed)[0];
    $this->assertSame('renewed.com', $renewal->getDomain());
    $this->assertEquals(new Price('10.88', 'USD'), $renewal->getAmount());
    $this->assertSame('dynadot', $renewal->get('provider')->target_id);
    $this->assertSame('2026-10-26', date('Y-m-d', $renewal->getPaid()));
    $this->assertFalse($renewal->isEstimated());
    $transfer = $history->forProduct($moved)[0];
    $this->assertSame(DomainRenewalInterface::TRANSFER, $transfer->getType());
    $this->assertSame(2, $transfer->getYears());
    $this->assertEquals(new Price('9.59', 'EUR'), $history->forProduct($euro)[0]->getAmount());

    // Again: nothing new.
    $plan = $importer->plan(RenewalImporter::parse($csv));
    $this->assertSame(['new' => 0, 'known' => 3, 'passed' => 5, 'problems' => 1], RenewalImporter::summary($plan));
    $this->assertSame(0, $importer->apply($plan));

    // A plain file: every row a renewal, and problems say why.
    $plan = $importer->plan(RenewalImporter::parse("domain,date,amount,registrar\nrenewed.com,2025-10-26,10 USD,dynadot\nrenewed.com,2025-10-26,10 USD,dynadot\nrenewed.com,,10 USD,\nrenewed.com,2024-10-26,10 XXX,\nrenewed.com,2023-10-26,10 USD,nobody\n"));
    $this->assertNull($plan[2]['problem']);
    $this->assertSame('Already on line 2.', $plan[3]['problem']);
    $this->assertSame('No date.', $plan[4]['problem']);
    $this->assertSame('Currency XXX is not on the site.', $plan[5]['problem']);
    $this->assertSame('No provider nobody: give a provider ID or name.', $plan[6]['problem']);
  }

  /**
   * Tests the record a registrar sync leaves, and an import replacing it.
   */
  public function testRecordFromSync(): void {
    $this->createProvider('dynadot');
    $this->createProvider('other');
    $product = $this->createDomain('Synced.com', 1000, [
      'domain_registrar' => 'dynadot',
      'domain_expires' => self::date('2026-12-01'),
      'domain_renewal_cost' => new Price('10', 'USD'),
    ]);
    $history = $this->container->get(RenewalHistory::class);
    $sync = function (string $expires, string $registrar = 'dynadot') use ($product): void {
      $product->set('domain_expires', self::date($expires))->set('domain_registrar', $registrar);
      $product->setSyncing(TRUE)->save();
      $product->setSyncing(FALSE);
    };

    // A correction of days, and an edit by hand, are no renewal.
    $sync('2026-12-05');
    $product->set('domain_expires', self::date('2027-12-05'))->save();
    $this->assertSame([], $history->forProduct($product));

    $sync('2029-12-05');
    $renewal = $history->forProduct($product)[0];
    $this->assertTrue($renewal->isEstimated());
    $this->assertSame(2, $renewal->getYears());
    $this->assertEquals(new Price('20', 'USD'), $renewal->getAmount());
    $this->assertSame(DomainRenewalInterface::RENEWAL, $renewal->getType());
    $sync('2030-12-05', 'other');
    $this->assertSame(DomainRenewalInterface::TRANSFER, $history->forProduct($product)[1]->getType());
    $this->assertCount(2, $history->forProduct($product));

    // What was charged replaces the estimates near it, and only those.
    $paid = date('Y-m-d', $this->now - 10 * self::DAY);
    $importer = $this->container->get(RenewalImporter::class);
    $importer->apply($importer->plan(RenewalImporter::parse("domain,date,amount\nsynced.com,$paid,10.88 USD\n")));
    $left = $history->forProduct($product);
    $this->assertCount(1, $left);
    $this->assertFalse($left[0]->isEstimated());

    // A sold domain's expiry date is no longer news.
    $product->set('domain_sale_price', new Price('5', 'USD'))->set('domain_sold', $this->now)->save();
    $sync('2035-12-05', 'other');
    $this->assertCount(1, $history->forProduct($product));
  }

  /**
   * Tests the registrars' own costs, and where a domain's cost comes from.
   */
  public function testRegistrarCosts(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $this->createProvider('dynadot');
    $this->createProvider('other');
    $this->setRenewalCosts([
      ['com', '11.81'],
      ['io', '51.80'],
      ['com', '10.88', 'USD', 'dynadot'],
      ['co.uk', '7', 'EUR', 'dynadot'],
    ]);

    $costs = $this->container->get(Costs::class);
    $at = $this->createDomain('At.com', 1000, ['domain_registrar' => 'dynadot']);
    $elsewhere = $this->createDomain('Elsewhere.com', 1000, ['domain_registrar' => 'other']);
    $none = $this->createDomain('None.com');
    $io = $this->createDomain('At.io', 1000, ['domain_registrar' => 'dynadot']);
    $this->assertEquals(new Price('10.88', 'USD'), $costs->renewalCost($at), "The registrar's.");
    $this->assertEquals(new Price('11.81', 'USD'), $costs->renewalCost($elsewhere), 'Nothing at that registrar: the general one.');
    $this->assertEquals(new Price('11.81', 'USD'), $costs->renewalCost($none));
    $this->assertEquals(new Price('51.80', 'USD'), $costs->renewalCost($io), 'Not that extension at that registrar.');
    $this->assertTrue($costs->renewsAtDefault($at));

    $this->container->get('router.builder')->rebuild();
    $note = fn(ProductInterface $product) => (string) $this->container->get('entity.form_builder')->getForm($product, 'domain_costs')['renewal_cost_note']['#markup'];
    $this->assertStringContainsString('$10.88 a year, the Dynadot cost', $note($at));
    $this->assertStringContainsString('$11.81 a year, the default', $note($elsewhere));
    $this->renewal($at, '2026-02-01', new Price('9', 'USD'));
    $this->assertStringContainsString('$9.00 a year, from its latest renewal', $note($at));
    $at->set('domain_renewal_cost', new Price('20', 'USD'))->save();
    $this->assertStringContainsString('$20.00 a year, set on this domain', $note($at));
    $this->assertEquals(new Price('20', 'USD'), $costs->renewalCost($at), 'The field still counts.');
  }

  /**
   * Tests the Costs tab and the form that adds a renewal by hand.
   */
  public function testForms(): void {
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], [], TRUE);
    $product = $this->createDomain('Tab.com');
    $form_builder = $this->container->get('entity.form_builder');
    $form = $form_builder->getForm($product, 'domain_costs');
    $this->assertArrayHasKey('renewals_paid', $form);
    $this->assertSame([], $form['renewals_paid']['table']['#rows']);
    $this->renewal($product, '2026-02-01', new Price('10.88', 'USD'));
    $form = $form_builder->getForm($product, 'domain_costs');
    $this->assertSame('$10.88', $form['renewals_paid']['table']['#rows'][0][3]);
    $this->assertArrayNotHasKey('renewals_paid', $form_builder->getForm($product, 'domain_sale'));

    $form = $form_builder->getForm(DomainRenewal::create(), 'add');
    foreach (['product', 'paid', 'years', 'amount', 'provider', 'type', 'estimated'] as $field) {
      $this->assertArrayHasKey($field, $form);
    }
    $this->assertNotFalse($form['product']['#access'] ?? TRUE, 'Reached from the list: pick the domain.');

    // From the domain's Costs tab: it is that domain, with nothing to pick.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->query->set('product', (string) $product->id());
    $form = $form_builder->getForm(DomainRenewal::create(), 'add');
    $this->assertFalse($form['product']['#access']);
    $this->assertStringContainsString('Tab.com', (string) $form['#title']);
    $form_state = (new FormState())->setValues([
      'paid' => [['value' => ['date' => '2026-03-01', 'time' => '12:00:00']]],
      'years' => [['value' => 2]],
      'amount' => [['number' => '20', 'currency_code' => 'USD']],
      'type' => 'renewal',
      'op' => 'Save',
    ]);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('commerce_domains_renewal', 'add');
    $form_object->setEntity(DomainRenewal::create());
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $saved = $this->container->get(RenewalHistory::class)->forProduct($product);
    $this->assertCount(2, $saved);
    $this->assertSame(2, $saved[1]->getYears());
    // A programmed submission doesn't redirect: ask where it would have gone.
    $form_state->setProgrammed(FALSE);
    $this->assertSame('commerce_domains.product_tab.domain_costs', $form_state->getRedirect()->getRouteName());
    $request->query->remove('product');

    // Editing keeps its domain.
    $form = $form_builder->getForm($saved[1], 'edit');
    $this->assertFalse($form['product']['#access']);

    $list = $this->container->get('entity_type.manager')->getListBuilder('commerce_domains_renewal')->render();
    $this->assertStringContainsString('tab.com', (string) $this->container->get('renderer')->renderInIsolation($list));
  }

}

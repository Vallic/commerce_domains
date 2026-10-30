<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Drush\Commands\RegistrarCommands;
use Drupal\commerce_domains\Form\DomainRegistrarForm;
use Drupal\commerce_domains\Form\RegistrationsForm;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Hook\FieldAccessHooks;
use Drupal\commerce_domains_owner\Hook\ProductHooks;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_domains_owner\Profit;
use Drupal\commerce_domains_owner\SalesReport;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the costs trait, the profit of sold domains and the sales report.
 */
#[CoversClass(DomainCosts::class)]
#[CoversClass(Costs::class)]
#[CoversClass(Profit::class)]
#[CoversClass(SalesReport::class)]
#[CoversClass(ProductHooks::class)]
#[CoversClass(FieldAccessHooks::class)]
#[CoversClass(RegistrarSync::class)]
#[CoversClass(RegistrarPlan::class)]
#[CoversClass(RegistrationsForm::class)]
#[CoversClass(RegistrarCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class CostsTest extends OwnerKernelTestBase {

  use DrushCommandTrait;

  /**
   * The costs service.
   *
   * @var \Drupal\commerce_domains_owner\Costs
   */
  protected Costs $costs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->costs = $this->container->get(Costs::class);
  }

  /**
   * Returns a Unix time for a date, at noon UTC.
   */
  protected static function date(string $date): int {
    return (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   * Tests installing and uninstalling the trait.
   */
  public function testTrait(): void {
    $type = ProductType::load('default');
    $this->assertContains(DomainCosts::ID, $type->getTraits());
    $fields = array_keys((new DomainCosts([], DomainCosts::ID, []))->buildFieldDefinitions());
    $this->assertSame(DomainCosts::FIELDS, $fields);
    foreach ($fields as $field) {
      $this->assertNotNull(FieldConfig::loadByName('commerce_product', 'default', $field), $field);
    }
    $form = $this->container->get('entity_display.repository')->getFormDisplay('commerce_product', 'default');
    $this->assertNotNull($form->getComponent('domain_renewal'));
    $this->assertNull($form->getComponent('domain_sale_order'), 'The sale order is set by sales, not by hand.');

    // The product form groups the fields.
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], [], TRUE);
    $product = $this->createDomain('Grouped.com');
    $built = $this->container->get('entity.form_builder')->getForm($product, 'edit');
    $this->assertSame('domain_details', $built['domain_expires']['#group']);
    $this->assertSame('domain_details', $built['domain_registrar']['#group']);
    $this->assertSame('domain_costs', $built['domain_purchase_price']['#group']);
    $this->assertSame('domain_sale', $built['domain_sold']['#group']);
    $this->assertSame('domain_owner', $built['domain_sale']['#group'], 'Costs and sale are tabs.');
    $this->assertSame('edit-domain-details', $built['domain_owner']['#default_tab'], 'The sale tab comes first once the domain sells.');

    // Only the owner sees what domains cost, and only the manager edits it.
    $buyer = $this->createUser(['view commerce_product']);
    $viewer = $this->createUser(['view domain portfolio']);
    $price = $product->get('domain_purchase_price');
    $this->assertFalse($price->access('view', $buyer));
    $this->assertTrue($price->access('view', $viewer));
    $this->assertFalse($price->access('edit', $viewer));
    $this->assertTrue($price->access('edit', $this->createUser(['manage domain portfolio'])));
    $this->assertTrue($product->get('domain_expires')->access('view', $buyer), 'Other fields are left alone.');
    $this->setUpCurrentUser([], ['administer commerce_product', 'administer commerce_store', 'view domain portfolio']);
    $built = $this->container->get('entity.form_builder')->getForm($product, 'edit');
    $this->assertFalse($built['domain_costs']['#access'], 'No empty group for staff who may not change costs.');

    // Uninstalling takes the trait off the type and keeps the data; a new
    // install brings it back.
    $product->set('domain_purchase_price', new Price('10', 'USD'))->save();
    commerce_domains_owner_uninstall(FALSE);
    $this->assertNotContains(DomainCosts::ID, ProductType::load('default')->getTraits());
    $this->assertNotNull(FieldConfig::loadByName('commerce_product', 'default', 'domain_purchase_price'));
    commerce_domains_owner_install(FALSE);
    $this->assertContains(DomainCosts::ID, ProductType::load('default')->getTraits());
    $this->assertEquals(new Price('10', 'USD'), Costs::price($this->reload($product), 'domain_purchase_price'));
  }

  /**
   * Tests counting the renewals paid while a domain was held.
   */
  public function testRenewals(): void {
    $date = self::date(...);
    // Registered, renewed each March, sold in June: three renewals.
    $this->assertSame(3, Costs::renewals($date('2020-03-01'), $date('2023-06-01'), $date('2024-03-01')));
    // Without an expiry date, the purchase date's anniversaries count.
    $this->assertSame(3, Costs::renewals($date('2020-03-01'), $date('2023-06-01')));
    // Bought with three months left: it renewed that September.
    $this->assertSame(1, Costs::renewals($date('2022-06-01'), $date('2023-01-01'), $date('2023-09-01')));
    // Sold before its first renewal, or sold on the day it was bought.
    $this->assertSame(0, Costs::renewals($date('2020-03-01'), $date('2021-02-01'), $date('2021-03-01')));
    $this->assertSame(0, Costs::renewals($date('2020-03-01'), $date('2020-03-01')));
    // A 29 February expiry renews on the 1 March of other years.
    $this->assertSame(2, Costs::renewals($date('2019-06-01'), $date('2021-06-01'), $date('2024-02-29')));
  }

  /**
   * Tests the profit of sold domains.
   */
  public function testProfit(): void {
    $date = self::date(...);
    $sold = $this->createDomain('Sold.com', 1000, [
      'domain_purchase_price' => new Price('100', 'USD'),
      'domain_purchase_date' => $date('2020-03-01'),
      'domain_renewal_cost' => new Price('10.50', 'USD'),
      'domain_expires' => $date('2024-03-01'),
      'domain_sale_price' => new Price('2500', 'USD'),
      'domain_sold' => $date('2023-06-01'),
    ]);
    $profit = $this->costs->profit($sold);
    $this->assertSame(3, $profit->renewals);
    $this->assertEquals(new Price('31.50', 'USD'), $profit->renewalsPaid);
    // 2500 - 100 - 3 × 10.50.
    $this->assertEquals(new Price('2368.50', 'USD'), $profit->profit);
    $this->assertNull($profit->problem);

    // Without a renewal cost, renewals cost nothing.
    $sold->set('domain_renewal_cost', NULL);
    $this->assertNull($this->costs->profit($sold)->renewalsPaid);
    $this->assertEquals(new Price('2400', 'USD'), $this->costs->profit($sold)->profit);

    // No profit without both prices.
    $sold->set('domain_purchase_price', NULL);
    $this->assertNull($this->costs->profit($sold)->profit);
    $this->assertSame('No purchase price.', (string) $this->costs->profit($sold)->problem);
    $unsold = $this->createDomain('Unsold.com', 1000, ['domain_purchase_price' => new Price('100', 'USD')]);
    $this->assertSame('No sale price.', (string) $this->costs->profit($unsold)->problem);

    // Costs in another currency need a rate, which Commerce Exchanger has.
    $sold->set('domain_purchase_price', new Price('100', 'EUR'));
    $this->assertNull($this->costs->profit($sold)->profit);
    $this->assertStringContainsString('another currency', (string) $this->costs->profit($sold)->problem);
  }

  /**
   * Tests the sales report: rows, where each sold, and totals by year.
   */
  public function testSalesReport(): void {
    $date = self::date(...);
    $this->createProvider('afternic', 'test_marketplace');
    $this->createDomain('Site.com', 1000, [
      'domain_purchase_price' => new Price('100', 'USD'),
      'domain_sale_price' => new Price('1000', 'USD'),
      'domain_sold' => $date('2025-05-01'),
      'domain_sale_order' => 7,
    ]);
    $this->createDomain('Market.com', 1000, [
      'domain_purchase_price' => new Price('50', 'USD'),
      'domain_sale_price' => new Price('500', 'USD'),
      'domain_sold' => $date('2026-02-01'),
      'domain_sold_via' => 'afternic',
    ]);
    $this->createDomain('NoPrice.com', 1000, ['domain_sold' => $date('2026-03-01')]);
    $this->createDomain('Held.com');
    $this->createDomain('Leased.com', 1000, [
      'domain_purchase_price' => new Price('10', 'USD'),
      'domain_sale_price' => new Price('3600', 'USD'),
      'domain_sold' => $date('2024-10-15'),
      'domain_sold_via' => 'afternic',
      'domain_lease_months' => 36,
    ]);

    $report = $this->container->get(SalesReport::class);
    $ids = $report->soldIds(10);
    $this->assertCount(4, $ids, 'Domains not sold are left out.');
    $table = $report->table($ids);
    $rows = array_values($table['#rows']);
    $this->assertSame('NoPrice.com', (string) $rows[0][0]['data']['#title'], 'Latest sale first.');
    $this->assertSame('Afternic', (string) $rows[1][2]['data']['#plain_text']);
    $this->assertSame('This site, order 7', (string) $rows[2][2]['data']['#title']);
    $this->assertSame('$900.00', $rows[2][6]);
    $this->assertSame('No sale price.', (string) $rows[0][6]['data']['#markup']);
    $this->assertSame('$500.00', $rows[1][3], 'Paid at once.');
    // A lease to own counts its payments in full, and says when it ends.
    $this->assertSame('$3,600.00, lease to own over 36 months to 2027-10', $rows[3][3]);
    $this->assertSame('$3,590.00', $rows[3][6]);

    $totals = $report->totals();
    $this->assertSame([2026, 2025, 2024], array_keys($totals));
    $this->assertSame(2, $totals[2026]['sales']);
    $this->assertSame(0, $totals[2026]['leases']);
    $this->assertSame(1, $totals[2024]['leases']);
    $this->assertSame(1, $totals[2026]['unknown']);
    $this->assertEquals(['USD' => new Price('450', 'USD')], $totals[2026]['profit']);
    $this->assertEquals(['USD' => new Price('1000', 'USD')], $totals[2025]['revenue']);
  }

  /**
   * Tests that blank dates stay empty, rather than becoming the save time.
   */
  public function testBlankDates(): void {
    $display = $this->container->get('entity_display.repository')->getFormDisplay('commerce_product', 'default');
    foreach (['domain_sold', 'domain_purchase_date', 'domain_expires'] as $field) {
      $this->assertSame('commerce_domains_optional_timestamp', $display->getComponent($field)['type'], $field);
    }
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => FieldConfig::loadByName('commerce_product', 'default', 'domain_sold'),
      'form_mode' => 'default',
      'configuration' => ['type' => 'commerce_domains_optional_timestamp', 'settings' => []],
    ]);
    $form_state = new FormState();
    $this->assertSame([], $widget->massageFormValues([['value' => NULL]], [], $form_state));
    $this->assertSame([], $widget->massageFormValues([['value' => ['date' => '', 'time' => '', 'object' => NULL]]], [], $form_state));
    $date = DrupalDateTime::createFromTimestamp(self::date('2026-10-01'));
    $this->assertSame([['value' => self::date('2026-10-01')]], $widget->massageFormValues([['value' => $date]], [], $form_state));
  }

  /**
   * Tests that a sale entered by hand takes the domain off sale.
   */
  public function testSaleByHand(): void {
    $product = $this->createDomain('Hand.com');
    $product->set('domain_purchase_price', new Price('10', 'USD'))->save();
    $this->assertTrue((bool) $this->reload($product)->getVariations()[0]->get('in_stock')->value, 'Other edits leave it on sale.');

    $product->set('domain_sold', self::date('2026-10-01'))->save();
    $this->assertFalse((bool) $this->reload($product)->getVariations()[0]->get('in_stock')->value);

    // Sales recorded by the site save as syncing, and see to it themselves.
    $quiet = $this->createDomain('Quiet.com');
    $quiet->set('domain_sold', self::date('2026-10-01'))->setSyncing(TRUE)->save();
    $this->assertTrue((bool) $this->reload($quiet)->getVariations()[0]->get('in_stock')->value);
  }

  /**
   * Tests auto-renew following the registrar, like the expiry date.
   */
  public function testAutoRenewFollowsRegistrar(): void {
    $provider = $this->createProvider('reg', 'test_managed_registrar');
    $this->container->get('state')->set('commerce_domains_test.managed', [
      'renews.com' => ['expires' => 1_820_000_000, 'auto_renew' => TRUE],
      'lapses.com' => ['expires' => 1_820_000_000, 'auto_renew' => FALSE],
      'silent.com' => ['expires' => 1_820_000_000],
    ]);
    $values = ['domain_registrar' => 'reg', 'domain_expires' => 1_820_000_000];
    $renews = $this->createDomain('Renews.com', 1000, $values);
    $lapses = $this->createDomain('Lapses.com', 1000, $values + ['domain_auto_renew' => TRUE]);
    $silent = $this->createDomain('Silent.com', 1000, $values);

    $sync = $this->container->get(RegistrarSync::class);
    $plan = $sync->plan($provider);
    $this->assertSame(['renews.com' => [NULL, TRUE], 'lapses.com' => [TRUE, FALSE]], $plan->autoRenew);
    $this->assertSame([], $plan->expires);
    $this->assertSame(1, $plan->unchanged, 'A registrar that says nothing leaves it alone.');
    $this->assertFalse($plan->isEmpty());

    $this->assertSame(2, $sync->apply($provider, $plan));
    $this->assertTrue((bool) $this->reload($renews)->get('domain_auto_renew')->value);
    $this->assertFalse((bool) $this->reload($lapses)->get('domain_auto_renew')->value);
    $this->assertTrue($this->reload($silent)->get('domain_auto_renew')->isEmpty());
    $this->assertTrue($sync->plan($provider)->isEmpty());
  }

  /**
   * Tests that a sold domain is neither managed nor missed at the registrar.
   */
  public function testSoldLeavesRegistrar(): void {
    $provider = $this->createProvider('reg', 'test_managed_registrar');
    $this->container->get('state')->set('commerce_domains_test.managed', []);
    $values = ['domain_registrar' => 'reg', 'domain_expires' => 1_820_000_000];
    $kept = $this->createDomain('Kept.com', 1000, $values);
    $sold = $this->createDomain('Sold.com', 1000, $values + ['domain_sold' => 1_700_000_000]);

    $this->assertSame(['kept.com'], $this->container->get(RegistrarSync::class)->plan($provider)->missing, 'The buyer took the sold one.');
    $this->assertTrue(DomainRegistrarForm::access($kept)->isAllowed());
    $this->assertFalse(DomainRegistrarForm::access($sold)->isAllowed(), 'The sold one has no Registrar tab.');
  }

  /**
   * Tests that the Registrations tab and Drush list auto-renew changes.
   */
  public function testAutoRenewListed(): void {
    $provider = $this->createProvider('reg', 'test_managed_registrar');
    $this->container->get('state')->set('commerce_domains_test.managed', [
      'renews.com' => ['expires' => 1_820_000_000, 'auto_renew' => TRUE],
      'lapses.com' => ['expires' => 1_820_000_000, 'auto_renew' => FALSE],
    ]);
    $values = ['domain_registrar' => 'reg', 'domain_expires' => 1_820_000_000];
    $this->createDomain('Renews.com', 1000, $values);
    $this->createDomain('Lapses.com', 1000, $values + ['domain_auto_renew' => TRUE]);
    $plan = $this->container->get(RegistrarSync::class)->plan($provider);

    // The tab offers Apply with the changes listed.
    $form_state = (new FormState())->set('plan', $plan)->addBuildInfo('args', [$provider]);
    $form = $this->container->get('form_builder')->buildForm(RegistrationsForm::class, $form_state);
    $summary = array_map(fn(array $row) => [(string) $row[0], $row[1]], $form['plan']['summary']['#rows']);
    $this->assertSame(['Expiry to update', 0], $summary[0]);
    $this->assertSame(['Auto-renew to update', 2], $summary[1]);
    $details = array_map(fn(array $row) => [$row[0], (string) $row[1]], $form['plan']['details']['table']['#rows']);
    $this->assertEqualsCanonicalizing([
      ['renews.com', 'auto-renew: unknown → on'],
      ['lapses.com', 'auto-renew: on → off'],
    ], $details);
    $this->assertTrue($form['actions']['apply']['#access']);

    // Drush lists them too; a dry run changes nothing.
    $this->requireDrush();
    $command = new RegistrarCommands($this->entityTypeManager, $this->container->get(RegistrarSync::class));
    $this->prepareCommand($command);
    $command->sync('reg', ['domains' => NULL, 'dry-run' => TRUE, 'details' => TRUE]);
    $output = $this->output->fetch();
    $this->assertMatchesRegularExpression('/Auto-renew to update\s+2/', $output);
    $this->assertMatchesRegularExpression('/renews\.com\s+auto-renew: unknown → on/', $output);
    $this->assertMatchesRegularExpression('/lapses\.com\s+auto-renew: on → off/', $output);
    $this->assertFalse($this->container->get(RegistrarSync::class)->plan($provider)->isEmpty());
  }

}

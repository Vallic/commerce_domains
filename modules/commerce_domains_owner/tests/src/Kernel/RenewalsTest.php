<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_owner\Form\ExpiringForm;
use Drupal\commerce_domains_owner\Hook\OwnerHooks;
use Drupal\commerce_domains_owner\Hook\ProductHooks;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the expiring list, keep-or-expire decisions and auto-renew.
 */
#[CoversClass(Renewals::class)]
#[CoversClass(ExpiringForm::class)]
#[CoversClass(ProductHooks::class)]
#[CoversClass(OwnerHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RenewalsTest extends OwnerKernelTestBase {

  /**
   * The renewals service.
   *
   * @var \Drupal\commerce_domains_owner\Renewals
   */
  protected Renewals $renewals;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renewals = $this->container->get(Renewals::class);
  }

  /**
   * Returns the titles of products, in order.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface[] $products
   *   The products.
   */
  protected static function titles(array $products): array {
    return array_values(array_map(fn(ProductInterface $product) => $product->getTitle(), $products));
  }

  /**
   * Tests which domains count as expiring soon.
   */
  public function testExpiringList(): void {
    $this->createDomain('Soon.com', 1000, ['domain_expires' => $this->now + 10 * self::DAY]);
    $this->createDomain('Later.com', 1000, ['domain_expires' => $this->now + 50 * self::DAY]);
    $this->createDomain('Far.com', 1000, ['domain_expires' => $this->now + 100 * self::DAY]);
    // Registrars still renew for a while after the expiry date.
    $this->createDomain('Lapsed.com', 1000, ['domain_expires' => $this->now - 5 * self::DAY]);
    $this->createDomain('Gone.com', 1000, ['domain_expires' => $this->now - 40 * self::DAY]);
    $this->createDomain('Sold.com', 1000, [
      'domain_expires' => $this->now + 5 * self::DAY,
      'domain_sold' => $this->now - self::DAY,
      'domain_sale_price' => new Price('900', 'USD'),
    ]);
    $this->createDomain('Undated.com');
    // The registrar's account has one of its two domains.
    $provider = $this->createProvider('reg');
    $at_reg = ['domain_registrar' => 'reg'];
    $this->createDomain('Moved.com', 1000, $at_reg + ['domain_expires' => $this->now + 20 * self::DAY]);
    $this->createDomain('Kept.com', 1000, $at_reg + ['domain_expires' => $this->now + 30 * self::DAY]);
    $this->container->get('state')->set('commerce_domains_test.registrations', ['kept.com' => $this->now + 30 * self::DAY]);
    $this->container->get(RegistrarSync::class)->plan($provider);

    $this->assertSame(60, $this->renewals->days());
    $this->assertSame(['Lapsed.com', 'Soon.com', 'Kept.com', 'Later.com'], self::titles($this->renewals->expiring(60)));
    $this->assertSame(['Lapsed.com', 'Soon.com'], self::titles($this->renewals->expiring(20)));
    $this->assertSame(['Lapsed.com'], self::titles($this->renewals->expiring(60, 1)));
    $this->assertSame(4, (int) $this->renewals->query(60)->count()->execute());
  }

  /**
   * Tests keeping domains and letting them expire.
   */
  public function testDecisions(): void {
    $this->config('commerce_domains_owner.settings')->set('off_sale_days', 14)->save();
    $near = $this->createDomain('Near.com', 1000, ['domain_expires' => $this->now + 10 * self::DAY]);
    $distant = $this->createDomain('Distant.com', 1000, ['domain_expires' => $this->now + 40 * self::DAY]);
    $this->assertSame('Not decided', (string) Renewals::decisionLabel($near));

    $this->renewals->decide([$near, $distant], DomainCosts::EXPIRE);
    $this->assertFalse($this->reload($near)->isPublished(), 'Within 14 days of its expiry: off sale at once.');
    $this->assertTrue($this->renewals->isOffSale($this->reload($near)));
    $this->assertTrue($this->reload($distant)->isPublished(), 'On sale until 14 days before its expiry.');
    $this->assertSame('Let it expire', (string) Renewals::decisionLabel($this->reload($distant)));

    // Cron takes it off sale when its time comes.
    $this->reload($distant)->set('domain_expires', $this->now + 7 * self::DAY)->save();
    $this->container->get('cron')->run();
    $this->assertFalse($this->reload($distant)->isPublished());

    // Keeping it again puts it back on sale, from the list or the form.
    $this->renewals->decide([$this->reload($near)], DomainCosts::KEEP);
    $this->assertTrue($this->reload($near)->isPublished());
    $this->assertFalse($this->renewals->isOffSale($this->reload($near)));
    $this->reload($distant)->set('domain_renewal', NULL)->save();
    $this->assertTrue($this->reload($distant)->isPublished(), 'Not decided again: back on sale.');

    // A domain someone put back on sale stays there.
    $this->renewals->decide([$this->reload($distant)], DomainCosts::EXPIRE);
    $this->assertFalse($this->reload($distant)->isPublished());
    $this->reload($distant)->setPublished()->save();
    $this->assertSame(0, $this->renewals->takeOffSale());
    $this->assertTrue($this->reload($distant)->isPublished());

    // A domain unpublished for another reason stays unpublished.
    $draft = $this->createDomain('Draft.com', 1000, ['domain_expires' => $this->now + 5 * self::DAY, 'status' => FALSE]);
    $this->renewals->decide([$draft], DomainCosts::KEEP);
    $this->assertFalse($this->reload($draft)->isPublished());

    // A deleted domain is forgotten.
    $this->reload($distant)->delete();
    $this->assertArrayNotHasKey($distant->id(), $this->container->get('state')->get(Renewals::OFF_SALE_KEY));
  }

  /**
   * Tests turning auto-renew on and off at the registrar.
   */
  public function testAutoRenewAtRegistrar(): void {
    $this->createProvider('managed', 'test_managed_registrar');
    $this->createProvider('reader');
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.managed', ['renew.com' => ['auto_renew' => FALSE]]);
    $account = fn() => $state->get('commerce_domains_test.managed');

    $renew = $this->createDomain('Renew.com', 1000, ['domain_registrar' => 'managed']);
    $changed = $renew->getChangedTime();
    $this->assertNull(Renewals::autoRenews($renew));
    $this->renewals->setAutoRenew($renew, TRUE);
    $this->assertTrue($account()['renew.com']['auto_renew']);
    $this->assertTrue(Renewals::autoRenews($this->reload($renew)));
    $this->assertSame($changed, $this->reload($renew)->getChangedTime(), "The registrar's setting is not an edit.");
    $this->renewals->setAutoRenew($this->reload($renew), FALSE);
    $this->assertFalse($account()['renew.com']['auto_renew']);
    $this->assertFalse(Renewals::autoRenews($this->reload($renew)));

    // The registrar refuses, can't, or is off.
    $failures = [
      'Refuse.com' => ['managed', 'Refused.'],
      'Reader.com' => ['reader', "The registrar of reader.com can't change renewal through its API."],
      'Nowhere.com' => [NULL, "The registrar of nowhere.com can't change renewal through its API."],
    ];
    foreach ($failures as $title => [$registrar, $message]) {
      $product = $this->createDomain($title, 1000, ['domain_registrar' => $registrar]);
      try {
        $this->renewals->setAutoRenew($product, TRUE);
        $this->fail("$title should not change.");
      }
      catch (ProviderException $e) {
        $this->assertSame($message, $e->getMessage());
        $this->assertNull(Renewals::autoRenews($this->reload($product)));
      }
    }
    Provider::load('managed')->disable()->save();
    $this->expectExceptionMessage('Managed is disabled.');
    $this->renewals->setAutoRenew($this->reload($renew), TRUE);
  }

  /**
   * Tests the Expiring soon page.
   */
  public function testExpiringForm(): void {
    $this->createProvider('managed', 'test_managed_registrar');
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.managed', ['alpha.com' => [], 'beta.com' => []]);
    $alpha = $this->createDomain('Alpha.com', 1000, [
      'domain_registrar' => 'managed',
      'domain_expires' => $this->now + 10 * self::DAY,
      'domain_renewal_cost' => new Price('12', 'USD'),
    ]);
    $beta = $this->createDomain('Beta.com', 1000, [
      'domain_registrar' => 'managed',
      'domain_expires' => $this->now + 20 * self::DAY,
      'domain_renewal_cost' => new Price('8', 'USD'),
      'domain_auto_renew' => FALSE,
    ]);
    $gamma = $this->createDomain('Gamma.com', 1000, ['domain_expires' => $this->now + 30 * self::DAY]);
    $form_builder = $this->container->get('form_builder');

    // Someone who may only look sees the list without the buttons.
    $this->setUpCurrentUser([], ['view domain portfolio']);
    $form = $form_builder->getForm(ExpiringForm::class);
    $this->assertSame([(int) $alpha->id(), (int) $beta->id(), (int) $gamma->id()], array_keys($form['domains']['#options']));
    $this->assertSame('Off', (string) $form['domains']['#options'][$beta->id()]['auto_renew']);
    $this->assertSame('Unknown', (string) $form['domains']['#options'][$alpha->id()]['auto_renew']);
    $this->assertStringContainsString('$20.00', (string) $form['costs']['#markup']);
    $this->assertFalse($form['actions']['keep']['#access']);
    $this->assertFalse($form['actions']['auto_renew_on']['#access']);

    // The owner lets two expire.
    $this->setUpCurrentUser([], ['view domain portfolio', 'manage domain portfolio', 'manage domains at registrars']);
    $form = $form_builder->getForm(ExpiringForm::class);
    $this->assertTrue($form['actions']['keep']['#access']);
    $this->assertTrue($form['actions']['auto_renew_on']['#access']);
    $submit = function (array $selected, string $op) use ($form_builder): FormStateInterface {
      $form_state = (new FormState())->setValues([
        'domains' => array_combine($selected, $selected),
        'op' => $op,
      ]);
      $form_builder->submitForm(ExpiringForm::class, $form_state);
      return $form_state;
    };
    $form_state = $submit([$alpha->id(), $gamma->id()], 'Let expire');
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(DomainCosts::EXPIRE, $this->reload($alpha)->get('domain_renewal')->value);
    $this->assertFalse($this->reload($alpha)->isPublished(), 'Off sale: it expires within 14 days.');
    $this->assertTrue($this->reload($gamma)->isPublished());
    $this->assertSame(DomainCosts::EXPIRE, $this->reload($gamma)->get('domain_renewal')->value);
    $this->assertArrayNotHasKey('auto_renew', $state->get('commerce_domains_test.managed')['alpha.com'], 'Deciding leaves the registrar alone.');
    $options = $form_builder->getForm(ExpiringForm::class)['domains']['#options'];
    $this->assertSame('Off sale: left to expire', (string) $options[$alpha->id()]['sale']);
    $this->assertSame('Let it expire', (string) $options[$gamma->id()]['decision']);
    $this->assertSame('For sale', (string) $options[$gamma->id()]['sale']);

    // Keeps one, and turns its auto-renew on.
    $submit([$alpha->id()], 'Keep');
    $this->assertSame(DomainCosts::KEEP, $this->reload($alpha)->get('domain_renewal')->value);
    $this->assertTrue($this->reload($alpha)->isPublished());
    $form_state = $submit([$alpha->id(), $beta->id()], 'Turn auto-renew on at the registrar');
    $this->assertSame([], $form_state->getErrors());
    $this->assertTrue($state->get('commerce_domains_test.managed')['alpha.com']['auto_renew']);
    $this->assertTrue($state->get('commerce_domains_test.managed')['beta.com']['auto_renew']);
    $this->assertTrue(Renewals::autoRenews($this->reload($beta)));

    // Nothing selected, or too many for the registrar at once.
    $this->assertArrayHasKey('domains', $submit([], 'Keep')->getErrors());
    $many = [];
    for ($i = 0; $i <= ExpiringForm::MAX_REGISTRAR_CHANGES; $i++) {
      $many[] = (int) $this->createDomain("many$i.com", 1000, ['domain_expires' => $this->now + 15 * self::DAY])->id();
    }
    $this->assertArrayHasKey('domains', $submit($many, 'Turn auto-renew off at the registrar')->getErrors());

    // A narrower window.
    $this->container->get('request_stack')->getCurrentRequest()->query->set('days', 5);
    $this->assertSame([], $form_builder->getForm(ExpiringForm::class)['domains']['#options']);
  }

}

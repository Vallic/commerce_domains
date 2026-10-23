<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests updating the catalog from registrar accounts.
 */
#[CoversClass(RegistrarSync::class)]
#[CoversClass(RegistrarPlan::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RegistrarSyncTest extends DomainsKernelTestBase {

  // Expiry dates the test account reports.
  protected const NEXT_YEAR = 1_820_000_000;

  protected const LATER = 1_830_000_000;

  /**
   * The registrar sync service.
   *
   * @var \Drupal\commerce_domains\RegistrarSync
   */
  protected RegistrarSync $sync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sync = $this->container->get(RegistrarSync::class);
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();

    // At this registrar: one current, one stale, one gone from the account.
    $this->createDomain('Current.com', 1000, ['domain_registrar' => 'reg', 'domain_expires' => self::NEXT_YEAR]);
    $this->createDomain('Renewed.com', 1000, ['domain_registrar' => 'reg', 'domain_expires' => self::NEXT_YEAR]);
    $this->createDomain('Gone.com', 1000, ['domain_registrar' => 'reg']);
    // In the account but recorded elsewhere, or nowhere.
    $this->createDomain('Moved.com', 1000, ['domain_registrar' => 'gname']);
    $this->createDomain('Unrecorded.com');
    $this->setAccount([
      'current.com' => self::NEXT_YEAR,
      'renewed.com' => self::LATER,
      'moved.com' => self::LATER,
      'unrecorded.com' => self::LATER,
      'parked.com' => self::LATER,
    ]);
  }

  /**
   * Creates a provider on the test registrar.
   */
  protected function createProvider(string $id = 'reg', bool $status = TRUE, bool $sync = FALSE): ProviderInterface {
    $provider = Provider::create([
      'id' => $id,
      'label' => ucfirst($id),
      'plugin' => 'test_registrar',
      'status' => $status,
    ]);
    $provider->setRegistrationSync($sync)->save();
    return $provider;
  }

  /**
   * Sets the test registrar's account: expiry times keyed by domain.
   */
  protected function setAccount(array $registrations): void {
    $this->container->get('state')->set('commerce_domains_test.registrations', $registrations);
  }

  /**
   * Loads the product of a domain.
   */
  protected function product(string $domain): Product {
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => $domain]);
    $product = reset($products);
    $this->assertInstanceOf(Product::class, $product);
    return $product;
  }

  /**
   * Tests sorting the account into the plan's buckets.
   */
  public function testPlan(): void {
    $plan = $this->sync->plan($this->createProvider());
    $this->assertSame(['renewed.com' => [self::NEXT_YEAR, self::LATER]], $plan->expires);
    $this->assertSame(1, $plan->unchanged);
    $this->assertSame(['gone.com'], $plan->missing);
    $this->assertSame(['moved.com' => 'gname', 'unrecorded.com' => NULL], $plan->elsewhere);
    $this->assertSame(['parked.com'], $plan->unknown);
    $this->assertFalse($plan->isEmpty());

    // Checking a few names reads only those.
    $few = $this->sync->plan(Provider::load('reg'), ['renewed.com', 'parked.com']);
    $this->assertSame(['renewed.com', 'parked.com'], array_keys($few->registrations));
    $this->assertSame(['renewed.com'], array_keys($few->expires));
    $this->assertSame([], $few->missing, 'Names that were not asked about are not missing.');
    $this->assertSame(['parked.com'], $few->unknown);
  }

  /**
   * Tests writing expiry dates, and optionally correcting registrars.
   */
  public function testApply(): void {
    $provider = $this->createProvider();
    $changed = $this->product('renewed.com')->getChangedTime();
    $this->assertSame(1, $this->sync->apply($provider, $this->sync->plan($provider)));
    $renewed = $this->product('renewed.com');
    $this->assertSame(self::LATER, (int) $renewed->get('domain_expires')->value);
    $this->assertSame($changed, $renewed->getChangedTime(), 'Registration details are not an edit.');
    $this->assertSame('gname', $this->product('moved.com')->get('domain_registrar')->target_id);

    $plan = $this->sync->plan($provider);
    $this->assertTrue($plan->isEmpty());
    $this->assertFalse($plan->isEmpty(TRUE));
    $this->assertSame(2, $this->sync->apply($provider, $plan, TRUE));
    $moved = $this->product('moved.com');
    $this->assertSame('reg', $moved->get('domain_registrar')->target_id);
    $this->assertSame(self::LATER, (int) $moved->get('domain_expires')->value);
    $this->assertSame('reg', $this->product('unrecorded.com')->get('domain_registrar')->target_id);
    $this->assertSame([], $this->sync->plan($provider)->elsewhere);
  }

  /**
   * Tests providers that can't be synced.
   */
  public function testRefusedProviders(): void {
    $disabled = $this->createProvider('off', FALSE);
    $plan = $this->sync->plan($disabled);
    try {
      $this->sync->apply($disabled, $plan);
      $this->fail('A disabled provider should not be synced.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Off is disabled.', $e->getMessage());
    }

    Provider::create(['id' => 'market', 'label' => 'Market', 'plugin' => 'test_marketplace'])->save();
    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('Market has no registrar API.');
    $this->sync->plan(Provider::load('market'));
  }

  /**
   * Tests the daily refresh on cron.
   */
  public function testCron(): void {
    $this->createProvider('reg', TRUE, TRUE);
    $this->createProvider('manual', TRUE, FALSE);
    $this->createProvider('off', FALSE, TRUE);
    $state = $this->container->get('state');
    $calls = fn() => $state->get('commerce_domains_test.registrar_calls', 0);

    $this->container->get('cron')->run();
    $this->assertSame(1, $calls(), 'Only the enabled provider that asked for it is read.');
    $this->assertSame(self::LATER, (int) $this->product('renewed.com')->get('domain_expires')->value);

    $this->sync->cron();
    $this->assertSame(1, $calls(), 'Not again within a day.');

    // An outage is retried on the next run; other failures wait a day.
    $state->delete('commerce_domains.registration_sync.reg');
    $state->set('commerce_domains_test.registrar_fail', 'retryable');
    $this->sync->cron();
    $this->sync->cron();
    $this->assertSame(3, $calls());
    $state->set('commerce_domains_test.registrar_fail', 'permanent');
    $this->sync->cron();
    $this->sync->cron();
    $this->assertSame(4, $calls());
  }

  /**
   * Tests the setting on the provider form and in the list.
   */
  public function testAdminUi(): void {
    $this->createProvider();
    Provider::create(['id' => 'dynadot', 'label' => 'Dynadot', 'plugin' => 'dynadot'])->save();
    Provider::create(['id' => 'afternic', 'label' => 'Afternic', 'plugin' => 'afternic'])->save();

    $rows = $this->entityTypeManager->getListBuilder('commerce_domains_provider')->render()['table']['#rows'];
    $this->assertSame('Marketplace, Registrar', (string) $rows['dynadot']['capabilities']);
    $this->assertSame('Marketplace', (string) $rows['afternic']['capabilities']);
    $this->assertSame('Registrar', (string) $rows['reg']['capabilities']);

    $form_builder = $this->container->get('entity.form_builder');
    $this->assertArrayHasKey('registration_sync', $form_builder->getForm(Provider::load('dynadot'), 'edit'));
    $this->assertArrayNotHasKey('registration_sync', $form_builder->getForm(Provider::load('afternic'), 'edit'));

    $form_object = $this->entityTypeManager->getFormObject('commerce_domains_provider', 'edit')->setEntity(Provider::load('reg'));
    $form_state = (new FormState())->setValues([
      'label' => 'Reg',
      'status' => 1,
      'registration_sync' => 1,
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertTrue(Provider::load('reg')->syncsRegistrations());
    $this->assertTrue($this->config('commerce_domains.provider.reg')->get('registration_sync'));
  }

}

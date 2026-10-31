<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Form\RegistrationsForm;
use Drupal\commerce_domains\Hook\ProductFormHooks;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests updating the catalog from registrar accounts.
 */
#[CoversClass(RegistrarSync::class)]
#[CoversClass(RegistrarPlan::class)]
#[CoversClass(RegistrationsForm::class)]
#[CoversClass(ProductFormHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RegistrarSyncTest extends DomainsKernelTestBase {

  use UserCreationTrait;

  // Expiry dates the test account reports.
  protected const NEXT_YEAR = 1_820_000_000;

  protected const LATER = 1_830_000_000;

  // A registration date.
  protected const EARLIER = 1_500_000_000;

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
    $this->assertNull($this->sync->lastCheck('gname'), 'Never read.');
    $plan = $this->sync->plan($this->createProvider());
    // A read of the whole account is recorded, for the domain overview.
    $check = $this->sync->lastCheck('reg');
    $this->assertIsArray($check);
    $this->assertSame(['gone.com'], $check['missing']);
    $this->assertEqualsWithDelta($this->container->get('datetime.time')->getCurrentTime(), $check['time'], 5);
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
    $this->assertSame($check, $this->sync->lastCheck('reg'), 'Reading a few names is no whole check.');
  }

  /**
   * Tests recording when the registrar says a domain was registered.
   */
  public function testRegistered(): void {
    $provider = $this->createProvider();
    $this->container->get('state')->set('commerce_domains_test.registered', ['current.com' => self::EARLIER]);
    $plan = $this->sync->plan($provider);
    $this->assertSame(['current.com' => [NULL, self::EARLIER]], $plan->registered);
    $this->assertSame(0, $plan->unchanged, 'Only the date was missing.');

    $this->sync->apply($provider, $plan);
    $this->assertSame(self::EARLIER, (int) $this->product('current.com')->get('domain_registered')->value);
    $this->assertSame([], $this->sync->plan($provider)->registered);
    $this->assertTrue($this->product('renewed.com')->get('domain_registered')->isEmpty(), 'Registrars that say nothing leave it alone.');

    // Like the expiry, the date then follows the registrar.
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], [], TRUE);
    $form = $this->container->get('entity.form_builder')->getForm($this->product('current.com'), 'domain_details');
    $this->assertTrue($form['domain_registered']['#disabled']);
  }

  /**
   * Tests locking the expiry once the registrar's details are in.
   */
  public function testExpiryLock(): void {
    // The product form needs a store the user can see, and date formats.
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->createProvider();
    $load = function (string $name): ProductInterface {
      $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => $name]);
      $product = reset($products);
      assert($product instanceof ProductInterface);
      return $product;
    };
    $locked = fn(string $name): bool => !empty($this->container->get('entity.form_builder')->getForm($load($name), 'domain_details')['domain_expires']['#disabled']);

    $this->assertFalse($locked('current.com'), 'Editable until the account is read.');
    $this->sync->plan(Provider::load('reg'));
    $this->assertSame('reg', $this->sync->registrationSource($load('current.com'))->id());
    $this->assertTrue($locked('current.com'));
    $this->assertNull($this->sync->registrationSource($load('gone.com')), 'Not in the account.');
    $this->assertNull($this->sync->registrationSource($load('moved.com')), 'GNAME has no API here.');

    // Without a stored date there is nothing to protect yet.
    $load('current.com')->set('domain_expires', NULL)->save();
    $this->assertFalse($locked('current.com'));

    Provider::load('reg')->disable()->save();
    $this->assertNull($this->sync->registrationSource($load('renewed.com')));
  }

  /**
   * Tests writing expiry dates and correcting registrars.
   */
  public function testApply(): void {
    $provider = $this->createProvider();
    $changed = $this->product('renewed.com')->getChangedTime();
    $this->assertSame(3, $this->sync->apply($provider, $this->sync->plan($provider)));
    $renewed = $this->product('renewed.com');
    $this->assertSame(self::LATER, (int) $renewed->get('domain_expires')->value);
    $this->assertSame($changed, $renewed->getChangedTime(), 'Registration details are not an edit.');
    $moved = $this->product('moved.com');
    $this->assertSame('reg', $moved->get('domain_registrar')->target_id, 'The account holding a domain is its registrar.');
    $this->assertSame(self::LATER, (int) $moved->get('domain_expires')->value);
    $this->assertSame('reg', $this->product('unrecorded.com')->get('domain_registrar')->target_id);
    $this->assertSame(['gone.com'], $this->sync->plan($provider)->missing, 'A domain gone from the account is left for a person.');
    $this->assertSame('reg', $this->product('gone.com')->get('domain_registrar')->target_id);
    $this->assertTrue($this->sync->plan($provider)->isEmpty());
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
    $this->assertSame('reg', $this->product('moved.com')->get('domain_registrar')->target_id, 'Cron corrects registrars too.');

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
    $this->assertSame('Marketplace, CSV export, Registrar, Domain management', (string) $rows['dynadot']['capabilities']);
    $this->assertSame('Marketplace, CSV export', (string) $rows['afternic']['capabilities']);
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

  /**
   * Tests comparing and applying from the Registrations page.
   */
  public function testRegistrationsForm(): void {
    $provider = $this->createProvider();
    Provider::create(['id' => 'afternic', 'label' => 'Afternic', 'plugin' => 'afternic'])->save();
    $this->assertTrue(RegistrationsForm::access($provider)->isAllowed());
    $this->assertFalse(RegistrationsForm::access(Provider::load('afternic'))->isAllowed(), 'Only registrars have the page.');
    $this->assertFalse(RegistrationsForm::access(Provider::load('gname'))->isAllowed(), 'Nor a provider without an API.');

    $state = $this->container->get('state');
    $calls = fn() => $state->get('commerce_domains_test.registrar_calls', 0);
    $form_builder = $this->container->get('form_builder');
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$provider]);
    $form = $form_builder->buildForm(RegistrationsForm::class, $form_state);
    $this->assertSame(0, $calls(), 'The account is read only when asked.');
    $this->assertArrayNotHasKey('apply', $form['actions']);

    // Compare: the plan is shown, and nothing changes yet.
    $form_state = (new FormState())->setValues(['domains' => '', 'op' => 'Compare with the account']);
    $form_state->addBuildInfo('args', [$provider]);
    $form_builder->submitForm(RegistrationsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(1, $calls());
    $plan = $form_state->get('plan');
    $this->assertInstanceOf(RegistrarPlan::class, $plan);
    $this->assertSame(['moved.com' => 'gname', 'unrecorded.com' => NULL], $plan->elsewhere);
    $this->assertSame('gname', $this->product('moved.com')->get('domain_registrar')->target_id);
    $form = $form_builder->retrieveForm(RegistrationsForm::class, $form_state);
    $this->assertTrue($form['actions']['apply']['#access']);
    $this->assertSame(['gone.com'], array_column(array_filter($form['plan']['details']['table']['#rows'], fn(array $row) => (string) $row[1] === 'not in the account'), 0));

    // Apply: the comparison as shown, without reading the account again.
    $plan = $form_state->get('plan');
    $form_state = (new FormState())->setValues(['domains' => '', 'op' => 'Apply'])->set('plan', $plan);
    $form_state->addBuildInfo('args', [$provider]);
    $form_builder->submitForm(RegistrationsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(1, $calls());
    $this->assertSame('reg', $this->product('moved.com')->get('domain_registrar')->target_id);
    $this->assertSame(self::LATER, (int) $this->product('renewed.com')->get('domain_expires')->value);

    // A few names.
    $values = ['domains' => "Renewed.com, parked.com\ngone.com", 'op' => 'Compare with the account'];
    $form_state = (new FormState())->setValues($values);
    $form_state->addBuildInfo('args', [$provider]);
    $form_builder->submitForm(RegistrationsForm::class, $form_state);
    $this->assertSame(['renewed.com', 'parked.com'], array_keys($form_state->get('plan')->registrations));
  }

}

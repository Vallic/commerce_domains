<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Controller\RegistrarTabController;
use Drupal\commerce_domains\Form\DomainRegistrarForm;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests managing a domain at its registrar.
 */
#[CoversClass(DomainRegistrarForm::class)]
#[CoversClass(RegistrarTabController::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainRegistrarFormTest extends DomainsKernelTestBase {

  use UserCreationTrait;

  protected const KEY = 'commerce_domains_test.managed';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dblog'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    Provider::create(['id' => 'managed', 'label' => 'Managed', 'plugin' => 'test_managed_registrar'])->save();
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar'])->save();
    Provider::create(['id' => 'hover', 'label' => 'Hover'])->save();
    $this->container->get('state')->set(self::KEY, [
      'example.com' => [
        'expires' => 1_820_000_000,
        'nameservers' => ['ns1.park.com', 'ns2.park.com'],
        'locked' => TRUE,
        'auto_renew' => FALSE,
      ],
      'refuse.com' => ['expires' => 1_820_000_000, 'locked' => FALSE],
      'mailed.com' => ['expires' => 1_820_000_000, 'emails_code' => TRUE],
    ]);
  }

  /**
   * Submits the form for a product with one button.
   */
  protected function submit(ProductInterface $product, string $op, array $values = []): FormStateInterface {
    $form_state = (new FormState())->setValues($values + ['op' => $op]);
    $form_state->addBuildInfo('args', [$product]);
    $this->container->get('form_builder')->submitForm(DomainRegistrarForm::class, $form_state);
    return $form_state;
  }

  /**
   * Returns the account's record of a domain.
   */
  protected function account(string $name): array {
    return $this->container->get('state')->get(self::KEY)[$name];
  }

  /**
   * Tests which domains have the page.
   */
  public function testAccess(): void {
    $this->assertTrue(DomainRegistrarForm::access($this->createDomain('Example.com', 1000, ['domain_registrar' => 'managed']))->isAllowed());
    $this->assertFalse(DomainRegistrarForm::access($this->createDomain('Read.com', 1000, ['domain_registrar' => 'reg']))->isAllowed(), 'A registrar that only reads.');
    $this->assertFalse(DomainRegistrarForm::access($this->createDomain('Hover.com', 1000, ['domain_registrar' => 'hover']))->isAllowed(), 'A registrar without an API.');
    $this->assertFalse(DomainRegistrarForm::access($this->createDomain('Nowhere.com'))->isAllowed());
  }

  /**
   * Tests the Registrar tab: its fields for every domain, the account below.
   */
  public function testTab(): void {
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], [
      'administer commerce_product',
      'administer commerce_store',
      'manage domains at registrars',
    ]);
    $controller = RegistrarTabController::create($this->container);
    $hover = $this->createDomain('Hover.com', 1000, ['domain_registrar' => 'hover']);
    $page = $controller->page($hover);
    $this->assertArrayHasKey('domain_registrar', $page['fields']);
    $this->assertArrayHasKey('domain_expires', $page['fields']);
    $this->assertArrayNotHasKey('account', $page, 'No API to manage it with.');
    $this->assertEmpty($page['fields']['domain_registrar']['#disabled'] ?? NULL, 'Chosen by hand.');
    $this->assertTrue(RegistrarTabController::access($hover, $this->container->get('current_user'))->isAllowed());

    // Once the registrar's API holds the domain, the choice is locked.
    $example = $this->createDomain('Example.com', 1000, ['domain_registrar' => 'managed']);
    $this->container->get(RegistrarSync::class)->plan(Provider::load('managed'));
    $example = $this->entityTypeManager->getStorage('commerce_product')->loadUnchanged($example->id());
    $this->assertInstanceOf(ProductInterface::class, $example);
    $page = $controller->page($example);
    $this->assertTrue($page['fields']['domain_registrar']['#disabled']);
    $this->assertArrayHasKey('details', $page['account']);
  }

  /**
   * Tests the page and each change.
   */
  public function testManage(): void {
    $product = $this->createDomain('Example.com', 1000, ['domain_registrar' => 'managed']);
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$product]);
    $form = $this->container->get('form_builder')->buildForm(DomainRegistrarForm::class, $form_state);
    // A field for each nameserver, and spares up to four.
    $this->assertSame(['ns1.park.com', 'ns2.park.com', '', ''], array_map(fn(int $i) => $form['nameservers'][$i]['#default_value'], range(0, 3)));
    $this->assertArrayNotHasKey(4, $form['nameservers']);
    $this->assertSame('Unlock transfers', (string) $form['actions']['lock']['#value']);
    $this->assertSame('Turn auto-renew on', (string) $form['actions']['renew']['#value']);

    $hosts = ['NS1.Host.com.', ' ns2.host.com', '', ''];
    $this->assertSame([], $this->submit($product, 'Save nameservers', ['nameservers' => $hosts])->getErrors());
    $this->assertSame(['ns1.host.com', 'ns2.host.com'], $this->account('example.com')['nameservers']);
    $same = ['ns1.host.com', 'NS1.host.com', '', ''];
    $errors = $this->submit($product, 'Save nameservers', ['nameservers' => $same])->getErrors();
    $this->assertSame('Enter at least two nameservers.', (string) $errors['nameservers][1']);
    $errors = $this->submit($product, 'Save nameservers', ['nameservers' => ['ns1.host.com', 'not a host', '', '']])->getErrors();
    $this->assertStringContainsString('is not a host name', (string) $errors['nameservers][1'], 'The error is on its field.');

    $this->submit($product, 'Unlock transfers');
    $this->assertFalse($this->account('example.com')['locked']);
    $this->submit($product, 'Turn auto-renew on');
    $this->assertTrue($this->account('example.com')['auto_renew']);

    $form_state = $this->submit($product, 'Get the transfer auth code');
    $this->assertSame('code-example.com', $form_state->get('auth_code'));
    $logs = $this->container->get('database')->select('watchdog', 'w')->fields('w', ['variables'])->execute()->fetchCol();
    $this->assertStringNotContainsString('code-example.com', implode(' ', $logs), 'An auth code is never logged.');

    $mailed = $this->createDomain('Mailed.com', 1000, ['domain_registrar' => 'managed']);
    $this->assertSame('', $this->submit($mailed, 'Get the transfer auth code')->get('auth_code'));
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains("The registrar emailed the auth code for mailed.com to the domain's administrative contact.", $messages);
  }

  /**
   * Tests a refused change, a disabled provider and a domain gone elsewhere.
   */
  public function testRefusals(): void {
    $refused = $this->createDomain('Refuse.com', 1000, ['domain_registrar' => 'managed']);
    $this->submit($refused, 'Lock transfers');
    $this->assertFalse($this->account('refuse.com')['locked']);
    $this->assertSame(['Managed refused: Refused.'], array_map('strval', $this->container->get('messenger')->messagesByType('error')));

    $gone = $this->createDomain('Gone.com', 1000, ['domain_registrar' => 'managed']);
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$gone]);
    $form = $this->container->get('form_builder')->buildForm(DomainRegistrarForm::class, $form_state);
    $this->assertArrayNotHasKey('nameservers', $form, 'Nothing to manage for a domain the account lacks.');

    Provider::load('managed')->disable()->save();
    $this->entityTypeManager->getStorage('commerce_product')->resetCache();
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$this->entityTypeManager->getStorage('commerce_product')->load($refused->id())]);
    $form = $this->container->get('form_builder')->buildForm(DomainRegistrarForm::class, $form_state);
    $this->assertArrayNotHasKey('actions', $form);
  }

}

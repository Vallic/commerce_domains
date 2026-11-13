<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_bulk\Kernel;

use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DnsTemplateApplier;
use Drupal\commerce_domains\Entity\DnsTemplate;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_bulk\Form\DnsTemplateForm;
use Drupal\commerce_domains_bulk\Plugin\Action\ApplyDnsTemplate;
use Drupal\Core\Form\FormState;
use Drupal\system\Entity\Action;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests applying a DNS template to a selection of domains.
 */
#[CoversClass(ApplyDnsTemplate::class)]
#[CoversClass(DnsTemplateForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DnsTemplateActionTest extends DomainsKernelTestBase {

  use UserCreationTrait;

  protected const KEY = 'commerce_domains_test.managed';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_pricing', 'commerce_domains_bulk', 'dblog'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['commerce_product', 'commerce_domains_pricing', 'commerce_domains_bulk']);
    Provider::create(['id' => 'managed', 'label' => 'Managed', 'plugin' => 'test_managed_registrar'])->save();
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar'])->save();
    $this->container->get('state')->set(self::KEY, [
      'one.com' => ['expires' => 1_820_000_000, 'dns' => [(new DnsRecord('TXT', '@', 'verify-me'))->toArray()]],
      'two.com' => ['expires' => 1_820_000_000, 'dns' => []],
      'away.com' => ['expires' => 1_820_000_000],
    ]);
    DnsTemplate::create(['id' => 'parked', 'label' => 'Parked'])
      ->setRecords([
        ['type' => 'A', 'host' => '@', 'value' => '192.0.2.1'],
        ['type' => 'CNAME', 'host' => 'www', 'value' => '{domain}'],
      ])
      ->save();
    $this->createUser();
  }

  /**
   * Tests who may use the action, and what it does.
   */
  public function testApply(): void {
    $one = $this->createDomain('One.com', 1000, ['domain_registrar' => 'managed']);
    $two = $this->createDomain('Two.com', 1000, ['domain_registrar' => 'managed']);
    $away = $this->createDomain('Away.com', 1000, ['domain_registrar' => 'managed']);
    $other = $this->createDomain('Other.com', 1000, ['domain_registrar' => 'reg']);

    $action = Action::load('commerce_domains_dns_template');
    $this->assertNotNull($action);
    $editor = $this->createUser([
      'access commerce_product overview',
      'update any default commerce_product',
      'manage default commerce_product_variation',
    ]);
    $this->assertFalse($action->getPlugin()->access($one, $editor), 'Needs the right to manage domains at registrars.');
    $this->setUpCurrentUser([], [
      'access commerce_product overview',
      'update any default commerce_product',
      'manage default commerce_product_variation',
      'manage domains at registrars',
    ]);
    $this->assertTrue($action->getPlugin()->access($one));

    $action->execute([$one, $two, $away, $other]);
    $form_state = (new FormState())->setValues(['template_id' => 'parked', 'template_mode' => DnsTemplateApplier::ADD]);
    $this->container->get('form_builder')->submitForm(DnsTemplateForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $account = $this->container->get('state')->get(self::KEY);
    $this->assertSame(['verify-me', '192.0.2.1', 'one.com'], array_column($account['one.com']['dns'], 'value'), 'Added beside what was there.');
    $this->assertSame(['192.0.2.1', 'two.com'], array_column($account['two.com']['dns'], 'value'));
    $this->assertArrayNotHasKey('dns', $account['away.com'], 'Nameservers elsewhere: left as it is.');
    $messages = implode(' ', array_map('strval', array_merge(...array_values($this->container->get('messenger')->all()))));
    $this->assertStringContainsString('Changed the DNS of 2 domains.', $messages);
    $this->assertStringContainsString('1 domain was left as it is', $messages);
    $this->assertStringContainsString('1 domain was skipped', $messages);
  }

}

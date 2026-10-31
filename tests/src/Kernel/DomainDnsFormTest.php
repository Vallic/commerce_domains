<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\DnsHistory;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Form\DomainDnsForm;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests editing a domain's DNS records at its registrar.
 */
#[CoversClass(DomainDnsForm::class)]
#[CoversClass(DnsHistory::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainDnsFormTest extends DomainsKernelTestBase {

  use UserCreationTrait;

  protected const KEY = 'commerce_domains_test.managed';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dblog'];

  /**
   * The zone example.com starts with.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $zone;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    // The date formats the earlier zones are listed with.
    $this->installConfig(['system']);
    Provider::create(['id' => 'managed', 'label' => 'Managed', 'plugin' => 'test_managed_registrar'])->save();
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar'])->save();
    $this->zone = [
      (new DnsRecord('A', '@', '192.0.2.1', ttl: 300))->toArray(),
      (new DnsRecord('MX', '@', 'mail.example.net', 10, 300))->toArray(),
      (new DnsRecord('FORWARD', 'www', 'https://example.net/', ttl: 300, extra: ['value2' => '2']))->toArray(),
    ];
    $this->container->get('state')->set(self::KEY, [
      'example.com' => ['expires' => 1_820_000_000, 'dns' => $this->zone],
      'elsewhere.com' => ['expires' => 1_820_000_000],
    ]);
  }

  /**
   * Returns the form, built for a domain, and its state.
   *
   * @return array{0: \Drupal\commerce_domains\Form\DomainDnsForm, 1: array, 2: \Drupal\Core\Form\FormState}
   *   The form object, the built form and the form state.
   */
  protected function form(ProductInterface $product): array {
    $object = DomainDnsForm::create($this->container);
    $form_state = new FormState();
    $form = $object->buildForm([], $form_state, $product);
    return [$object, $form, $form_state];
  }

  /**
   * Returns the domain's records at the registrar.
   *
   * @return array<int, array<string, mixed>>
   *   Records as arrays.
   */
  protected function zone(string $name): array {
    return $this->container->get('state')->get(self::KEY)[$name]['dns'] ?? [];
  }

  /**
   * Tests which domains have the tab.
   */
  public function testAccess(): void {
    $owner = $this->createUser(['manage domains at registrars']);
    $other = $this->createUser(['administer commerce_product']);
    $managed = $this->createDomain('Example.com', 1000, ['domain_registrar' => 'managed']);
    $this->assertTrue(DomainDnsForm::access($managed, $owner)->isAllowed());
    $this->assertFalse(DomainDnsForm::access($managed, $other)->isAllowed(), 'Needs the right.');
    $this->assertFalse(DomainDnsForm::access($this->createDomain('Read.com', 1000, ['domain_registrar' => 'reg']), $owner)->isAllowed(), 'A registrar without DNS.');
    $this->assertFalse(DomainDnsForm::access($this->createDomain('Nowhere.com'), $owner)->isAllowed());
  }

  /**
   * Tests a domain whose nameservers are elsewhere.
   */
  public function testElsewhere(): void {
    [, $form] = $this->form($this->createDomain('Elsewhere.com', 1000, ['domain_registrar' => 'managed']));
    $this->assertArrayHasKey('elsewhere', $form);
    $this->assertArrayNotHasKey('records', $form);
  }

  /**
   * Tests editing, reviewing, applying and restoring a zone.
   */
  public function testEditReviewApply(): void {
    $product = $this->createDomain('Example.com', 1000, ['domain_registrar' => 'managed']);
    [$object, $form, $form_state] = $this->form($product);
    $this->assertSame([0, 1, 2], array_values(array_filter(array_keys($form['records']), 'is_int')));
    $this->assertSame('FORWARD', $form['records'][2]['type']['#default_value']);

    // Remove the MX and add a TXT; an empty added row is no record.
    $rows = $this->zone;
    $rows[1]['remove'] = TRUE;
    $rows[3] = (new DnsRecord('TXT', '_dmarc', 'v=DMARC1; p=none'))->toArray();
    $rows[4] = (new DnsRecord('A', '@', ''))->toArray();
    $form_state->setValue('records', $rows);
    $object->validateRecords($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $object->review($form, $form_state);
    $form = $object->buildForm([], $form_state, $product);
    $changes = array_map(fn(array $row) => (string) $row['data'][0], $form['changes']['#rows']);
    $this->assertSame(['Remove', 'Add'], $changes);
    $this->assertSame($this->zone, $this->zone('example.com'), 'Nothing written before applying.');

    // Applied: the old zone is kept, the new one written whole.
    $object->apply($form, $form_state);
    $zone = $this->zone('example.com');
    $this->assertCount(3, $zone);
    $this->assertSame(['A', 'FORWARD', 'TXT'], array_column($zone, 'type'));
    $this->assertSame(['value2' => '2'], $zone[1]['extra'], 'The forward kept its redirect kind.');
    $history = $this->container->get(DnsHistory::class);
    $this->assertCount(1, $history->list('example.com'));
    $this->assertEquals(array_map(DnsRecord::fromArray(...), $this->zone), $history->records('example.com', 0));

    // A bad record stops at validation.
    [$object, $form, $form_state] = $this->form($product);
    $rows = $this->zone('example.com');
    $rows[0]['value'] = '999.1.1.1';
    $form_state->setValue('records', $rows);
    $object->validateRecords($form, $form_state);
    $this->assertNotEmpty($form_state->getErrors());

    // One the registrar holds already is not checked, whatever its format.
    $account = $this->container->get('state')->get(self::KEY);
    $account['example.com']['dns'][] = (new DnsRecord('SRV', '_sip._tcp', '{"registrar":"format"}', 10))->toArray();
    $this->container->get('state')->set(self::KEY, $account);
    [$object, $form, $form_state] = $this->form($product);
    $form_state->setValue('records', $this->zone('example.com'));
    $object->validateRecords($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $account['example.com']['dns'] = array_slice($account['example.com']['dns'], 0, -1);
    $this->container->get('state')->set(self::KEY, $account);

    // A change at the registrar after the page was read stops the write.
    [$object, $form, $form_state] = $this->form($product);
    $rows = $this->zone('example.com');
    $rows[] = (new DnsRecord('A', 'shop', '192.0.2.9'))->toArray();
    $form_state->setValue('records', $rows);
    $object->review($form, $form_state);
    $form = $object->buildForm([], $form_state, $product);
    $account = $this->container->get('state')->get(self::KEY);
    $account['example.com']['dns'][] = (new DnsRecord('TXT', '@', 'made elsewhere'))->toArray();
    $this->container->get('state')->set(self::KEY, $account);
    $object->apply($form, $form_state);
    $this->assertCount(4, $this->zone('example.com'));
    $this->assertNotContains('192.0.2.9', array_column($this->zone('example.com'), 'value'), 'Not overwritten unseen.');
    $this->assertCount(1, $history->list('example.com'));

    // The kept zone can be put back.
    [$object, $form, $form_state] = $this->form($product);
    $this->assertArrayHasKey('history', $form);
    $form_state->setTriggeringElement(['#zone' => 0]);
    $object->restore($form, $form_state);
    $form = $object->buildForm([], $form_state, $product);
    $object->apply($form, $form_state);
    $this->assertEquals($this->zone, $this->zone('example.com'));
    $this->assertCount(2, $history->list('example.com'), 'The zone before the restore is kept too.');
  }

}

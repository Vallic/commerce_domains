<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\DnsHistory;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DnsTemplateApplier;
use Drupal\commerce_domains\Entity\DnsTemplate;
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
#[CoversClass(DnsTemplate::class)]
#[CoversClass(DnsTemplateApplier::class)]
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
      'empty.com' => ['expires' => 1_820_000_000, 'dns' => []],
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

  /**
   * Tests adding the first record to a zone with none.
   */
  public function testEmptyZone(): void {
    $product = $this->createDomain('Empty.com', 1000, ['domain_registrar' => 'managed']);
    [$object, $form, $form_state] = $this->form($product);
    $this->assertSame([], array_values(array_filter(array_keys($form['records']), 'is_int')));

    // An empty table posts an empty string.
    $form_state->setValue('records', '');
    $object->addRecord($form, $form_state);
    $form = $object->buildForm([], $form_state, $product);
    $this->assertSame([0], array_values(array_filter(array_keys($form['records']), 'is_int')));

    $form_state->setValue('records', [(new DnsRecord('A', '@', '192.0.2.5'))->toArray()]);
    $object->validateRecords($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $object->review($form, $form_state);
    $form = $object->buildForm([], $form_state, $product);
    $object->apply($form, $form_state);
    $this->assertSame(['192.0.2.5'], array_column($this->zone('empty.com'), 'value'));
  }

  /**
   * Tests DNS templates: on the DNS tab, and applied at once.
   */
  public function testTemplate(): void {
    $template = DnsTemplate::create(['id' => 'parked', 'label' => 'Parked']);
    $template->setRecords([
      ['type' => 'A', 'host' => '@', 'value' => '192.0.2.1'],
      ['type' => 'CNAME', 'host' => 'WWW', 'value' => '{domain}.'],
      ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 -all', 'ttl' => '3600'],
      ['type' => 'FORWARD', 'host' => 'shop', 'value' => 'https://{domain}/shop'],
    ]);
    $template->save();
    $this->assertSame('www', DnsTemplate::load('parked')->getRecords()[1]['host']);
    $this->assertNull(DnsTemplate::load('parked')->getRecords()[1]['note']);
    $this->assertSame(3600, DnsTemplate::load('parked')->getRecords()[2]['ttl']);
    $this->assertSame('example.com.', DnsTemplate::load('parked')->recordsFor('example.com')[1]->value);

    // On the tab, adding: the A record is there already.
    $product = $this->createDomain('Example.com', 1000, ['domain_registrar' => 'managed']);
    [$object, $form, $form_state] = $this->form($product);
    $this->assertArrayHasKey('template', $form);
    $form_state->setValues([
      'records' => $this->zone,
      'template_id' => 'parked',
      'template_mode' => DnsTemplateApplier::ADD,
    ]);
    $object->useTemplate($form, $form_state);
    $rows = $form_state->get('rows');
    $this->assertSame(['A', 'MX', 'FORWARD', 'CNAME', 'TXT'], array_column($rows, 'type'));
    $this->assertSame([FALSE, FALSE, TRUE, FALSE, FALSE], array_column($rows, 'remove'), 'The CNAME takes the place of the forward on www.');
    $warnings = array_map('strval', $this->container->get('messenger')->messagesByType('warning'));
    $this->assertStringContainsString('FORWARD', $warnings[0], 'A type the registrar lacks is left out.');
    $this->assertSame($this->zone, $this->zone('example.com'), 'Nothing written before review.');

    // Replacing: what is there is ticked for removal, to review.
    $form_state->setValues([
      'records' => $this->zone,
      'template_id' => 'parked',
      'template_mode' => DnsTemplateApplier::REPLACE,
    ]);
    $object->useTemplate($form, $form_state);
    $rows = $form_state->get('rows');
    $this->assertSame([FALSE, TRUE, TRUE, FALSE, FALSE], array_column($rows, 'remove'), 'The A record the template has too stays.');
    $form_state->setValue('records', $rows);
    $object->review($form, $form_state);
    $form = $object->buildForm([], $form_state, $product);
    $changes = array_map(fn(array $row) => (string) $row['data'][0], $form['changes']['#rows']);
    $this->assertSame(['Remove', 'Remove', 'Add', 'Add'], $changes, 'The A record stays.');

    // A host's own forward stays when the template's is another host's.
    $zone = DnsTemplateApplier::zone(
      [new DnsRecord('FORWARD', '@', 'https://a.example/'), new DnsRecord('FORWARD', 'www', 'https://a.example/')],
      [new DnsRecord('FORWARD', '@', 'https://b.example/')],
      DnsTemplateApplier::ADD,
    );
    $this->assertSame(
      ['www https://a.example/', '@ https://b.example/'],
      array_map(fn(DnsRecord $record) => $record->host . ' ' . $record->value, $zone),
    );

    // At once, as the bulk action does.
    $applier = $this->container->get(DnsTemplateApplier::class);
    $this->assertSame(DnsTemplateApplier::CHANGED, $applier->apply($product, $template, DnsTemplateApplier::ADD));
    $this->assertSame(['A', 'MX', 'CNAME', 'TXT'], array_column($this->zone('example.com'), 'type'));
    $this->assertCount(1, $this->container->get(DnsHistory::class)->list('example.com'), 'The zone before is kept.');
    $this->assertSame(DnsTemplateApplier::UNCHANGED, $applier->apply($product, $template, DnsTemplateApplier::ADD));
    $this->assertSame(DnsTemplateApplier::CHANGED, $applier->apply($product, $template, DnsTemplateApplier::REPLACE));
    $this->assertSame(['A', 'CNAME', 'TXT'], array_column($this->zone('example.com'), 'type'));
    $this->assertSame(DnsTemplateApplier::ELSEWHERE, $applier->apply($this->createDomain('Elsewhere.com', 1000, ['domain_registrar' => 'managed']), $template, DnsTemplateApplier::ADD));
    $this->assertSame(DnsTemplateApplier::SKIPPED, $applier->apply($this->createDomain('Read.com', 1000, ['domain_registrar' => 'reg']), $template, DnsTemplateApplier::ADD));

    // A URL redirect becomes the registrar's own kind, where it has one.
    $redirect = DnsTemplate::create(['id' => 'lander', 'label' => 'Lander'])->setRecords([
      ['type' => 'REDIRECT', 'host' => '@', 'value' => 'https://shop.example/domain/{domain}'],
      ['type' => 'REDIRECT', 'host' => 'www', 'value' => 'https://shop.example/domain/{domain}'],
      ['type' => 'TXT', 'host' => '_brandstore', 'value' => 'token'],
    ]);
    $types = fn(string ...$types) => array_flip(['A', 'TXT', ...$types]);
    [$records] = $applier->records($redirect, 'example.com', $types('FORWARD', 'STEALTH'));
    $this->assertSame(['FORWARD', 'FORWARD', 'TXT'], array_map(fn(DnsRecord $record) => $record->type, $records), 'Dynadot');
    $this->assertSame('https://shop.example/domain/example.com', $records[0]->value);
    [$records] = $applier->records($redirect, 'example.com', $types('URL'));
    $this->assertSame(['URL', 'URL', 'TXT'], array_map(fn(DnsRecord $record) => $record->type, $records), 'GNAME');
    [$records] = $applier->records($redirect, 'example.com', $types('URL301', 'URL', 'FRAME'));
    $this->assertSame('URL', $records[0]->type, 'Namecheap: the 302.');
    [$records, $left_out] = $applier->records($redirect, 'example.com', $types());
    $this->assertSame(['TXT'], array_map(fn(DnsRecord $record) => $record->type, $records));
    $this->assertSame(['URL redirect'], $left_out);
    // Applied over a host's own forward, it takes its place.
    $zone = DnsTemplateApplier::zone([new DnsRecord('FORWARD', '@', 'https://old.example/')], $applier->records($redirect, 'example.com', $types('FORWARD'))[0], DnsTemplateApplier::ADD);
    $this->assertSame(['https://shop.example/domain/example.com'], array_column(array_map(fn(DnsRecord $record) => $record->toArray(), array_filter($zone, fn(DnsRecord $record) => $record->host === '@')), 'value'));

    $this->expectException(\InvalidArgumentException::class);
    $template->setRecords(array_fill(0, 21, ['type' => 'A', 'host' => '@', 'value' => '192.0.2.1']));
  }

  /**
   * Tests the template form: records, notes and the limit.
   */
  public function testTemplateForm(): void {
    $this->setUpCurrentUser([], ['administer commerce_domains providers']);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('commerce_domains_dns_template', 'add')
      ->setEntity(DnsTemplate::create());
    $form_state = (new FormState())->setValues([
      'label' => 'For sale',
      'id' => 'for_sale',
      'records' => [
        ['type' => 'REDIRECT', 'host' => '@', 'value' => 'https://shop.example/domain/{domain}', 'note' => ''],
        ['type' => 'TXT', 'host' => '@', 'value' => 'sedo-token', 'note' => ' Sedo '],
        ['type' => 'TXT', 'host' => '@', 'value' => '', 'note' => 'Dropped: no value'],
        ['type' => 'TXT', 'host' => '@', 'value' => 'gone', 'note' => 'Removed', 'remove' => 1],
      ],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], array_map('strval', $form_state->getErrors()));
    $records = DnsTemplate::load('for_sale')->getRecords();
    $this->assertSame(['REDIRECT', 'TXT'], array_column($records, 'type'));
    $this->assertSame([NULL, 'Sedo'], array_column($records, 'note'));
    $this->assertSame('sedo-token', DnsTemplate::load('for_sale')->recordsFor('example.com')[1]->value, 'The note is not part of the record.');
    $rows = $this->container->get('entity_type.manager')->getListBuilder('commerce_domains_dns_template')->render()['table']['#rows'];
    $this->assertSame('REDIRECT @, TXT @ (Sedo)', $rows['for_sale']['records']);

    // A redirect needs a web address.
    $form_object = $this->container->get('entity_type.manager')->getFormObject('commerce_domains_dns_template', 'edit')
      ->setEntity(DnsTemplate::load('for_sale'));
    $form_state = (new FormState())->setValues([
      'label' => 'For sale',
      'id' => 'for_sale',
      'records' => [['type' => 'REDIRECT', 'host' => '@', 'value' => 'shop.example', 'note' => '']],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertNotEmpty($form_state->getErrors());
  }

}

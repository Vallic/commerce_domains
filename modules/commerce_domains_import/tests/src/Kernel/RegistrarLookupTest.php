<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_domains_import\RdapRecord;
use Drupal\commerce_domains_import\RegistrarLookup;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding registrars in RDAP, and the providers they stand for.
 */
#[CoversClass(RegistrarLookup::class)]
#[CoversClass(RdapRecord::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RegistrarLookupTest extends ImportKernelTestBase {

  // The expiry date the RDAP answers give.
  protected const EXPIRES = '2027-08-13T04:00:00Z';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // IANA's bootstrap file, with a service that lists HTTP first.
    $this->responses[RegistrarLookup::BOOTSTRAP] = [
      'version' => '1.0',
      'services' => [
        [['com', 'net'], ['http://rdap.test/com/', 'https://rdap.test/com/']],
        [['org'], ['https://rdap.test/org']],
      ],
    ];
    $registrars = [
      'godaddy' => 'GoDaddy',
      'dynadot' => 'Dynadot',
      'atom' => 'Atom',
      'unstoppable' => 'Unstoppable Domains',
    ];
    foreach ($registrars as $id => $label) {
      Provider::create(['id' => $id, 'label' => $label])->save();
    }
    Provider::create(['id' => 'reg', 'label' => 'Name Registrar', 'plugin' => 'test_registrar'])->save();
    Provider::create(['id' => 'afternic', 'label' => 'Afternic', 'plugin' => 'afternic'])->save();
  }

  /**
   * Returns an RDAP domain object, as registries answer.
   */
  protected static function rdap(string $name, string $registrar): array {
    return [
      'objectClassName' => 'domain',
      'ldhName' => strtoupper($name),
      'events' => [
        ['eventAction' => 'registration', 'eventDate' => '2015-08-13T04:00:00Z'],
        ['eventAction' => 'expiration', 'eventDate' => self::EXPIRES],
      ],
      'entities' => [
        [
          'objectClassName' => 'entity',
          'roles' => ['technical'],
          'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Not the registrar']]],
        ],
        [
          'objectClassName' => 'entity',
          'handle' => '472',
          'roles' => ['registrar'],
          'publicIds' => [['type' => 'IANA Registrar ID', 'identifier' => '472']],
          'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', $registrar]]],
        ],
      ],
    ];
  }

  /**
   * Tests matching registrar names to providers.
   */
  public function testProviders(): void {
    $lookup = $this->container->get(RegistrarLookup::class);
    $this->assertSame('godaddy', $lookup->provider('GoDaddy.com, LLC'));
    $this->assertSame('godaddy', $lookup->provider('Go Daddy, LLC'));
    $this->assertSame('dynadot', $lookup->provider('DYNADOT LLC'));
    $this->assertSame('unstoppable', $lookup->provider('Unstoppable Domains Inc.'));
    $this->assertSame('reg', $lookup->provider('reg'), 'Provider IDs match.');
    $this->assertSame('reg', $lookup->provider('NAME REGISTRAR, INC.'), 'Providers with a registrar API match.');
    $this->assertNull($lookup->provider('Atomic Domains Ltd'), 'Only whole words match.');
    $this->assertNull($lookup->provider('Afternic'), 'A marketplace-only provider is no registrar.');
    $this->assertNull($lookup->provider('Tucows Domains Inc.'));
  }

  /**
   * Tests looking registrars up while importing.
   */
  public function testImport(): void {
    $this->responses['https://rdap.test/com/domain/looked.com'] = self::rdap('looked.com', 'Dynadot Inc');
    $this->responses['https://rdap.test/com/domain/tucows.com'] = self::rdap('tucows.com', 'Tucows Domains Inc.');
    $this->responses['https://rdap.test/com/domain/busy.net'] = new Response(429);
    $csv = "domain,price,registrar\nlooked.com,10,\ntucows.com,10,\nnamed.com,10,GoDaddy\ngone.com,10,\nexample.io,10,\nbusy.net,10,\nbusy-too.com,10,\n";

    $report = $this->import(ImportParser::parseCsv($csv), ['lookupRegistrars' => TRUE]);
    $this->assertCount(7, $report->created, 'Lookups never stop a row.');
    $this->assertSame([], $report->errors);
    $looked = $this->product('looked.com');
    $this->assertSame('dynadot', $looked->get('domain_registrar')->target_id);
    $this->assertSame(strtotime(self::EXPIRES), (int) $looked->get('domain_expires')->value);
    $this->assertTrue($this->product('tucows.com')->get('domain_registrar')->isEmpty());
    $this->assertSame(strtotime(self::EXPIRES), (int) $this->product('tucows.com')->get('domain_expires')->value, 'The expiry is kept without a provider.');
    $this->assertSame('godaddy', $this->product('named.com')->get('domain_registrar')->target_id);

    $this->assertSame([
      'No provider for the registrar Tucows Domains Inc.: add one at Commerce → Configuration → Domains → Domain providers, then import again with updates to record it.' => 1,
      'RDAP has no record of some domains; check that they are registered.' => 1,
      'No RDAP service for .io domains.' => 1,
      'rdap.test refused more lookups for now.' => 2,
    ], $report->notes);

    // The bootstrap once, then only the domains without a registrar, by
    // HTTPS; none after the service refused more.
    $this->assertSame([
      RegistrarLookup::BOOTSTRAP,
      'https://rdap.test/com/domain/looked.com',
      'https://rdap.test/com/domain/tucows.com',
      'https://rdap.test/com/domain/gone.com',
      'https://rdap.test/com/domain/busy.net',
    ], $this->requested());

    // Answers are kept.
    $record = $this->container->get(RegistrarLookup::class)->lookup('looked.com');
    $this->assertEquals(new RdapRecord('Dynadot Inc', '472', strtotime(self::EXPIRES)), $record);
    $this->assertCount(5, $this->requested(), 'No new request.');
  }

  /**
   * Tests importing while the list of RDAP services can't be read.
   */
  public function testNoBootstrap(): void {
    unset($this->responses[RegistrarLookup::BOOTSTRAP]);
    $report = $this->import(ImportParser::parseList("one.com 10\ntwo.com 10"), ['lookupRegistrars' => TRUE]);
    $this->assertSame(['one.com', 'two.com'], $report->created);
    $this->assertSame(['The list of RDAP services could not be read.' => 2], $report->notes);
    $this->assertSame([RegistrarLookup::BOOTSTRAP], $this->requested(), 'The list is asked for once.');
  }

}

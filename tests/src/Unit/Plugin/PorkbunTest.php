<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Porkbun;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Porkbun provider.
 */
#[CoversClass(Porkbun::class)]
#[CoversClass(PorkbunClient::class)]
#[Group('commerce_domains')]
class PorkbunTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'pk1_abc', 'secret_key' => 'sk1_Secret9', 'requests_per_minute' => 60000];

  /**
   * Tests reading the account 1,000 domains at a time.
   */
  public function testFetchRegistrations(): void {
    $full = array_map(fn($i) => ['domain' => "d$i.com"], range(1, 1000));
    $plugin = $this->registrar(Porkbun::class, 'porkbun', self::KEYS, [
      self::json(['status' => 'SUCCESS', 'count' => 1000, 'domains' => $full]),
      self::json([
        'status' => 'SUCCESS',
        'count' => 1,
        'domains' => [
          [
            'domain' => 'A.com',
            'status' => 'ACTIVE',
            'expireDate' => '2027-01-15 10:00:00',
            'securityLock' => 1,
            'autoRenew' => '0',
          ],
        ],
      ]),
    ]);
    $registrations = $plugin->fetchRegistrations();
    $this->assertCount(1001, $registrations);
    $this->assertEquals(new Registration('a.com', 1800007200, TRUE, FALSE, 'active'), $registrations['a.com']);
    $this->assertSame('POST https://api.porkbun.com/api/json/v3/domain/listAll', $this->call(0));
    $this->assertSame(['start' => 0], $this->body(0));
    $this->assertSame(['start' => 1000], $this->body(1));
    $this->assertSame('pk1_abc', $this->header(0, 'X-API-Key'));
    $this->assertSame('sk1_Secret9', $this->header(0, 'X-Secret-API-Key'));
  }

  /**
   * Tests fetching a few names, and errors reported in the body.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(Porkbun::class, 'porkbun', self::KEYS, [
      self::json(['status' => 'SUCCESS', 'domain' => ['domain' => 'a.com', 'securityLock' => 0]]),
      self::json(['status' => 'ERROR', 'code' => 'DOMAIN_NOT_FOUND', 'message' => 'Not found.'], 404),
      self::json(['status' => 'ERROR', 'code' => 'API_ACCESS_DISABLED', 'message' => 'Opt in sk1_Secret9 first.']),
    ]);
    $expected = ['a.com' => new Registration('a.com', NULL, FALSE)];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $this->assertSame('GET https://api.porkbun.com/api/json/v3/domain/get/gone.com', $this->call(1));

    try {
      $plugin->fetchRegistrations(['b.com']);
      $this->fail('An error status fails the call.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Porkbun GET domain/get/b.com: HTTP 200: Opt in *** first. (API_ACCESS_DISABLED)', $e->getMessage());
      $this->assertFalse($e->retryable);
    }
  }

  /**
   * Tests changing domains in the account.
   */
  public function testManagement(): void {
    $plugin = $this->registrar(Porkbun::class, 'porkbun', self::KEYS, [
      self::json(['status' => 'SUCCESS', 'ns' => ['Ns2.Host.com', 'ns1.host.com']]),
      self::json(['status' => 'SUCCESS']),
      self::json(['status' => 'SUCCESS', 'results' => ['a.com' => ['status' => 'SUCCESS']]]),
      self::json([
        'status' => 'SUCCESS',
        'results' => ['a.com' => ['status' => 'ERROR', 'message' => 'API access is off']],
      ]),
    ]);
    $this->assertInstanceOf(DomainManagementInterface::class, $plugin);
    $this->assertSame(['ns1.host.com', 'ns2.host.com'], $plugin->getNameservers('a.com'));
    $this->assertSame('GET https://api.porkbun.com/api/json/v3/domain/getNs/a.com', $this->call(0));
    $plugin->setNameservers('a.com', ['ns1.x.com', 'ns2.x.com']);
    $this->assertSame('POST https://api.porkbun.com/api/json/v3/domain/updateNs/a.com', $this->call(1));
    $this->assertSame(['ns' => ['ns1.x.com', 'ns2.x.com']], $this->body(1));
    $plugin->setAutoRenew('a.com', FALSE);
    $this->assertSame(['status' => 'off'], $this->body(2));
    try {
      $plugin->setAutoRenew('a.com', TRUE);
      $this->fail('A refused domain is an error.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Porkbun updateAutoRenew a.com: API access is off', $e->getMessage());
    }

    foreach (['setTransferLock' => ['a.com', FALSE], 'getAuthCode' => ['a.com']] as $method => $arguments) {
      try {
        $plugin->$method(...$arguments);
        $this->fail("$method has no API endpoint.");
      }
      catch (ProviderException $e) {
        $this->assertStringContainsString('in the Porkbun account', $e->getMessage());
      }
    }
    $this->assertCount(4, $this->history, 'Unsupported operations make no call.');
  }

  /**
   * Tests reading DNS records and writing only what changes.
   */
  public function testDnsRecords(): void {
    $zone = [
      'status' => 'SUCCESS',
      'records' => [
        ['id' => '101', 'name' => 'a.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => '600', 'prio' => '0'],
        ['id' => '102', 'name' => 'www.a.com', 'type' => 'CNAME', 'content' => 'a.com', 'ttl' => '600', 'prio' => NULL],
        ['id' => '103', 'name' => 'a.com', 'type' => 'TXT', 'content' => 'old', 'ttl' => '600', 'prio' => NULL],
      ],
    ];
    $ours = ['status' => 'SUCCESS', 'ns' => ['curitiba.ns.porkbun.com', 'maceio.ns.porkbun.com']];
    $plugin = $this->registrar(Porkbun::class, 'porkbun', self::KEYS, [
      self::json($ours),
      self::json($zone),
      self::json(['status' => 'SUCCESS', 'ns' => ['ns1.elsewhere.net']]),
      // The write: nameservers and zone read again, then one delete, one
      // create and one TTL change.
      self::json($ours),
      self::json($zone),
      self::json(['status' => 'SUCCESS']),
      self::json(['status' => 'SUCCESS', 'id' => '104']),
      self::json(['status' => 'SUCCESS']),
    ]);
    $records = $plugin->getDnsRecords('a.com');
    $this->assertEquals([
      new DnsRecord('A', '@', '192.0.2.1', ttl: 600, id: '101'),
      new DnsRecord('CNAME', 'www', 'a.com', ttl: 600, id: '102'),
      new DnsRecord('TXT', '@', 'old', ttl: 600, id: '103'),
    ], $records);
    $this->assertSame('GET https://api.porkbun.com/api/json/v3/dns/retrieve/a.com', $this->call(1));
    $this->assertNull($plugin->getDnsRecords('b.com'), 'Nameservers elsewhere: the records there do not apply.');

    $plugin->setDnsRecords('a.com', [
      $records[0]->withTtl(3600),
      $records[1],
      new DnsRecord('TXT', '@', 'new'),
    ]);
    $this->assertSame('POST https://api.porkbun.com/api/json/v3/dns/delete/a.com/103', $this->call(5));
    $this->assertSame('POST https://api.porkbun.com/api/json/v3/dns/create/a.com', $this->call(6));
    $this->assertSame(['name' => '', 'type' => 'TXT', 'content' => 'new', 'ttl' => '600'], $this->body(6));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->header(6, 'Idempotency-Key'));
    $this->assertSame('POST https://api.porkbun.com/api/json/v3/dns/edit/a.com/101', $this->call(7));
    $this->assertSame(['name' => '', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => '3600'], $this->body(7));
    $this->assertCount(8, $this->history, 'The CNAME that stays is not written.');
  }

}

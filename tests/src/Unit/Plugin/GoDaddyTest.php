<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use GuzzleHttp\Psr7\Response;
use Drupal\commerce_domains\Clients\GoDaddyClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\GoDaddy;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the GoDaddy provider.
 */
#[CoversClass(GoDaddy::class)]
#[CoversClass(GoDaddyClient::class)]
#[Group('commerce_domains')]
class GoDaddyTest extends ProviderTestBase {

  protected const KEYS = ['api_token' => 'gd-Tok-1', 'requests_per_minute' => 60000];

  /**
   * Tests reading the account page by page.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      self::json([
        'items' => [
          [
            'domain' => 'A.com',
            'createdAt' => '2016-07-08T09:10:11Z',
            'expiresAt' => '2027-06-12T10:02:10Z',
            'autoRenew' => TRUE,
            'transferLock' => TRUE,
            'status' => 'ACTIVE',
          ],
        ],
        'links' => [['rel' => 'next', 'href' => '/v3/domains/domain-names?pageSize=200&pageToken=abc']],
      ]),
      self::json([
        'items' => [['domain' => 'b.com', 'status' => 'EXPIRED']],
        'links' => [['rel' => 'self', 'href' => '/v3/x']],
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1812794530, TRUE, TRUE, 'active', registered: 1467969011),
      'b.com' => new Registration('b.com', NULL, NULL, NULL, 'expired'),
    ], $plugin->fetchRegistrations());
    $this->assertSame('GET https://api.godaddy.com/v3/domains/domain-names', $this->call(0));
    $this->assertSame('Bearer gd-Tok-1', $this->header(0, 'Authorization'));
    $this->assertSame(['pageSize' => '200', 'pageToken' => 'abc'], $this->query(1));
  }

  /**
   * Tests that a next link to another host is not followed.
   */
  public function testForeignNextLink(): void {
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      self::json([
        'items' => [['domain' => 'a.com']],
        'links' => [['rel' => 'next', 'href' => 'https://evil.example/v3/domains/domain-names']],
      ]),
    ]);
    $this->assertCount(1, $plugin->fetchRegistrations());
    $this->assertCount(1, $this->history, 'The token is never sent elsewhere.');
  }

  /**
   * Tests fetching a few names one by one.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      self::json(['domain' => 'a.com', 'transferLock' => FALSE]),
      self::json(['name' => 'NOT_FOUND', 'message' => 'Not found'], 404),
    ]);
    $expected = ['a.com' => new Registration('a.com', NULL, FALSE)];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $this->assertSame('GET https://api.godaddy.com/v3/domains/domain-names/gone.com', $this->call(1));
  }

  /**
   * Tests reading DNS records and writing only what changes.
   */
  public function testDnsRecords(): void {
    $ours = self::json(['domain' => 'a.com', 'nameServers' => ['NS01.domaincontrol.com', 'ns02.domaincontrol.com']]);
    $srv = [
      'recordId' => 's1',
      'name' => '@',
      'type' => 'SRV',
      'data' => 'sip.a.com',
      'ttl' => 3600,
      'priority' => 10,
      'weight' => 5,
      'port' => 5060,
      'service' => '_sip',
      'protocol' => '_tcp',
    ];
    $zone = self::json([
      'items' => [
      [
        'recordId' => 'soa',
        'name' => '@',
        'type' => 'SOA',
        'data' => 'ns01.domaincontrol.com. dns.jomax.net.',
        'ttl' => 600,
      ],
      ['recordId' => 'ns1', 'name' => '@', 'type' => 'NS', 'data' => 'ns01.domaincontrol.com', 'ttl' => 3600],
      ['recordId' => 'a1', 'name' => '@', 'type' => 'A', 'data' => '192.0.2.1', 'ttl' => 600],
      [
        'recordId' => 'mx1',
        'name' => '@',
        'type' => 'MX',
        'data' => 'mail.example.net',
        'ttl' => 3600,
        'priority' => 10,
      ],
      [
        'recordId' => 'caa1',
        'name' => '@',
        'type' => 'CAA',
        'data' => 'letsencrypt.org',
        'ttl' => 3600,
        'flag' => 0,
        'tag' => 'issue',
      ],
        $srv,
      ],
      'links' => [],
    ]);
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      $ours,
      $zone,
      self::json(['domain' => 'b.com', 'nameServers' => ['ns1.elsewhere.net']]),
      $ours,
      $zone,
      new Response(204),
      self::json(['recordId' => 'new1'], 201),
      self::json(['recordId' => 'a1']),
    ]);
    $records = $plugin->getDnsRecords('a.com');
    $this->assertEquals([
      new DnsRecord('A', '@', '192.0.2.1', ttl: 600, id: 'a1'),
      new DnsRecord('MX', '@', 'mail.example.net', 10, 3600, 'mx1'),
      new DnsRecord('CAA', '@', '0 issue "letsencrypt.org"', ttl: 3600, id: 'caa1'),
    ], array_slice($records, 0, 3), "GoDaddy's SOA and the domain's NS are left out.");
    $this->assertSame('_sip._tcp', $records[3]->host);
    $this->assertSame('5 5060 sip.a.com', $records[3]->value);
    $this->assertSame('GET https://api.godaddy.com/v3/domains/zones/a.com/dns-records', $this->call(1));
    $this->assertSame(['pageSize' => '100'], $this->query(1));
    $this->assertNull($plugin->getDnsRecords('b.com'), 'Nameservers elsewhere.');

    // The MX goes, a TXT comes, the A gets a new TTL; the SRV stays.
    $plugin->setDnsRecords('a.com', [
      $records[0]->withTtl(3600),
      $records[2],
      $records[3],
      new DnsRecord('TXT', '_dmarc', 'v=DMARC1; p=none'),
    ]);
    $this->assertSame('DELETE https://api.godaddy.com/v3/domains/zones/a.com/dns-records/mx1', $this->call(5));
    $this->assertSame('POST https://api.godaddy.com/v3/domains/zones/a.com/dns-records', $this->call(6));
    $this->assertSame([
      'name' => '_dmarc',
      'type' => 'TXT',
      'data' => 'v=DMARC1; p=none',
      'ttl' => 3600,
    ], $this->body(6));
    $this->assertSame('PUT https://api.godaddy.com/v3/domains/zones/a.com/dns-records/a1', $this->call(7));
    $this->assertSame(['name' => '@', 'type' => 'A', 'data' => '192.0.2.1', 'ttl' => 3600], $this->body(7));
    $this->assertCount(8, $this->history);
  }

}

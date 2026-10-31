<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Clients\NameSiloClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\NameSilo;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the NameSilo provider.
 */
#[CoversClass(NameSilo::class)]
#[CoversClass(NameSiloClient::class)]
#[Group('commerce_domains')]
class NameSiloTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'NsKey11'];

  /**
   * Wraps a reply in NameSilo's envelope.
   */
  protected static function reply(array $reply, string $operation = 'op'): array {
    return [
      'request' => ['operation' => $operation, 'ip' => '203.0.113.7'],
      'reply' => $reply + ['code' => 300, 'detail' => 'success'],
    ];
  }

  /**
   * Tests listings: a hidden reserve, and payment plans up to 12 months.
   */
  public function testBuildListing(): void {
    $plugin = $this->plugin(NameSilo::class, 'namesilo');
    $this->assertSame(
      ['buy_now' => 1299, 'reserve' => 649, 'payment_plan_months' => 0, 'description' => 'Short.'],
      $plugin->buildListing(self::item('a.com', 1299, ['description' => ' Short. ']))
    );
    $this->assertSame(12, $plugin->buildListing(self::item('b.com', 60000))['payment_plan_months']);
    $this->assertNull($plugin->buildListing(self::item('free.com', 0)));
    $this->assertTrue($plugin->manages(self::item('c.com', 1000, [
      'registrar' => 'ns',
      'registrarPlugin' => 'namesilo',
    ])));
    $at_dynadot = self::item('d.com', 1000, ['registrar' => 'dynadot', 'registrarPlugin' => 'dynadot']);
    $this->assertFalse($plugin->manages($at_dynadot));
  }

  /**
   * Tests reading sales, and which of them are left alone.
   */
  public function testFetchListings(): void {
    $plugin = $this->plugin(NameSilo::class, 'namesilo', self::KEYS, [
      self::json(self::reply([
        'sale_details' => [
          [
            'domain' => 'A.com',
            'status' => 'active',
            'reserve' => '649.00 (hidden)',
            'buy_now' => '1299.00',
            'sale_type' => 'Offer',
          ],
          [
            'domain' => 'b.com',
            'status' => 'awaiting payment',
            'reserve' => '0.00',
            'buy_now' => '150.50',
            'sale_type' => 'Offer',
          ],
          ['domain' => 'c.com', 'status' => 'active', 'reserve' => '25.00', 'sale_type' => 'Auction'],
          ['domain' => 'other.com', 'status' => 'active'],
        ],
      ])),
    ]);
    $listings = $plugin->fetchListings(['a.com', 'b.com', 'c.com']);
    $this->assertSame([
      'buy_now' => 1299,
      'reserve' => 649,
      'status' => 'active',
      'sale_type' => 'Offer',
    ], $listings['a.com']);
    $this->assertSame(150.5, $listings['b.com']['buy_now']);
    $this->assertArrayNotHasKey('other.com', $listings);
    $this->assertNull($plugin->lockReason($listings['a.com']));
    $this->assertSame('awaiting payment', $plugin->lockReason($listings['b.com']));
    $this->assertSame('auction', $plugin->lockReason($listings['c.com']));
    $this->assertSame([], $plugin->changedFields([
      'buy_now' => 1299,
      'reserve' => 649,
      'payment_plan_months' => 6,
    ], $listings['a.com']));
    $this->assertSame('$1,299', $plugin->describeValue('buy_now', 1299));

    $this->assertSame('GET https://www.namesilo.com/api/marketplaceActiveSalesOverview', $this->call(0));
    $this->assertSame(['version' => '1', 'type' => 'json', 'key' => 'NsKey11'], $this->query(0));
  }

  /**
   * Tests adding, changing and cancelling sales.
   */
  public function testSaveAndDelete(): void {
    $ok = self::json(self::reply(['message' => 'Your selected domains have been added to our marketplace!']));
    $plugin = $this->plugin(NameSilo::class, 'namesilo', self::KEYS, [
      $ok,
      $ok,
      self::json(self::reply(['code' => '210', 'detail' => 'General error'])),
    ]);
    $errors = $plugin->saveListings([
      'new.com' => ['buy_now' => 9000, 'reserve' => 4500, 'payment_plan_months' => 12, 'description' => 'Nice name'],
      'old.com' => ['buy_now' => 1000, 'reserve' => 500, 'payment_plan_months' => 0],
    ], ['old.com' => ['buy_now' => 900]]);
    $this->assertSame([], $errors);
    $this->assertSame([
      'version' => '1',
      'type' => 'json',
      'key' => 'NsKey11',
      'domain' => 'new.com',
      'action' => 'add',
      'buy_now' => '9000',
      'reserve' => '4500',
      'show_reserve' => '0',
      'payment_plan_offered' => '1',
      'payment_plan_months' => '12',
      'sale_type' => 'offer_counter_offer',
      'end_date_use_maximum' => '1',
      'description' => 'Nice name',
    ], $this->query(0));
    $modify = $this->query(1);
    $this->assertSame(['modify', '0'], [$modify['action'], $modify['payment_plan_offered']]);
    $this->assertArrayNotHasKey('sale_type', $modify);

    $this->assertSame(['gone.com' => 'NameSilo marketplaceAddOrModifySale: General error (code 210)'], $plugin->deleteListings(['gone.com']));
    $this->assertSame(['modify', '1'], [$this->query(2)['action'], $this->query(2)['cancel_sale']]);
  }

  /**
   * Tests registrations from the list, and from single-domain details.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(NameSilo::class, 'namesilo', self::KEYS, [
      self::json(self::reply([
        'domains' => [['domain' => 'A.com', 'created' => '2020-02-03', 'expires' => '2027-02-03']],
      ])),
      // A single domain comes as an object, in the older wrapped form.
      self::json(self::reply(['domains' => ['domain' => 'b.com']])),
      self::json(self::reply([
        'expires' => '2027-02-03',
        'locked' => 'Yes',
        'auto_renew' => 'No',
        'status' => 'Active',
      ])),
      self::json(self::reply(['code' => '200', 'detail' => 'Domain is not active, or does not belong to this user'])),
      self::json(self::reply(['code' => '110', 'detail' => 'Invalid API Key'])),
    ]);
    $this->assertEquals(['a.com' => new Registration('a.com', 1801612800, registered: 1580688000)], $plugin->fetchRegistrations());
    $this->assertEquals(['b.com' => new Registration('b.com', NULL)], $plugin->fetchRegistrations());
    $this->assertEquals(
      ['a.com' => new Registration('a.com', 1801612800, TRUE, FALSE, 'active')],
      $plugin->fetchRegistrations(['a.com', 'gone.com'])
    );
    $this->assertSame(['domain' => 'gone.com'], array_diff_key($this->query(3), array_flip([
      'version',
      'type',
      'key',
    ])));

    try {
      $plugin->fetchRegistrations();
      $this->fail('A refused key fails the call.');
    }
    catch (ProviderException $e) {
      $this->assertSame('NameSilo listDomains: Invalid API Key (code 110)', $e->getMessage());
      $this->assertTrue($e->retryable);
    }
  }

  /**
   * Tests changing domains in the account.
   */
  public function testManagement(): void {
    $ok = self::json(self::reply([]));
    $nameservers = [
      ['nameserver' => 'NS2.Example.net', 'position' => 2],
      ['nameserver' => 'ns1.example.net', 'position' => 1],
    ];
    $plugin = $this->registrar(NameSilo::class, 'namesilo', self::KEYS, [
      self::json(self::reply(['nameservers' => $nameservers])),
      $ok,
      $ok,
      $ok,
      $ok,
    ]);
    $this->assertInstanceOf(DomainManagementInterface::class, $plugin);
    $this->assertSame(['ns1.example.net', 'ns2.example.net'], $plugin->getNameservers('a.com'));
    $plugin->setNameservers('a.com', ['ns1.x.com', 'ns2.x.com']);
    $query = array_diff_key($this->query(1), ['key' => TRUE, 'version' => TRUE, 'type' => TRUE]);
    $this->assertSame(['domain' => 'a.com', 'ns1' => 'ns1.x.com', 'ns2' => 'ns2.x.com'], $query);
    $this->assertSame('GET https://www.namesilo.com/api/changeNameServers', $this->call(1));
    $plugin->setTransferLock('a.com', FALSE);
    $this->assertSame('GET https://www.namesilo.com/api/domainUnlock', $this->call(2));
    $plugin->setAutoRenew('a.com', TRUE);
    $this->assertSame('GET https://www.namesilo.com/api/addAutoRenewal', $this->call(3));
    $this->assertSame('', $plugin->getAuthCode('a.com'), 'NameSilo emails the code.');
    $this->assertSame('GET https://www.namesilo.com/api/retrieveAuthCode', $this->call(4));
  }

  /**
   * Tests reading DNS records and writing only what changes.
   */
  public function testDnsRecords(): void {
    $dnsowl = [
      ['nameserver' => 'NS1.dnsowl.com', 'position' => 1],
      ['nameserver' => 'ns2.dnsowl.com', 'position' => 2],
    ];
    $ours = self::json(self::reply(['nameservers' => $dnsowl]));
    $elsewhere = ['nameserver' => 'ns1.elsewhere.net', 'position' => 1];
    $lone = ['record_id' => 'r9', 'type' => 'TXT', 'host' => 'x.a.com', 'value' => 'v', 'ttl' => '3600'];
    $zone = self::json(self::reply([
      'resource_record' => [
      [
        'record_id' => 'r1',
        'type' => 'A',
        'host' => 'a.com',
        'value' => '192.0.2.1',
        'ttl' => '7207',
        'distance' => '0',
      ],
      [
        'record_id' => 'r2',
        'type' => 'MX',
        'host' => 'a.com',
        'value' => 'mail.example.net',
        'ttl' => '7207',
        'distance' => '10',
      ],
      ],
    ]));
    $plugin = $this->registrar(NameSilo::class, 'namesilo', self::KEYS, [
      $ours,
      $zone,
      self::json(self::reply(['nameservers' => [$elsewhere]])),
      // One record comes as a lone object.
      $ours,
      self::json(self::reply(['resource_record' => $lone])),
      // The write.
      $ours,
      $zone,
      self::json(self::reply([])),
      self::json(self::reply(['record_id' => 'r3'])),
      self::json(self::reply(['record_id' => 'r4'])),
    ]);
    $records = $plugin->getDnsRecords('a.com');
    $this->assertEquals([
      new DnsRecord('A', '@', '192.0.2.1', ttl: 7207, id: 'r1'),
      new DnsRecord('MX', '@', 'mail.example.net', 10, 7207, 'r2'),
    ], $records);
    $this->assertSame(['domain' => 'a.com'], array_intersect_key($this->query(1), ['domain' => 1]));
    $this->assertNull($plugin->getDnsRecords('a.com'), 'Nameservers elsewhere.');
    $this->assertEquals([new DnsRecord('TXT', 'x', 'v', ttl: 3600, id: 'r9')], $plugin->getDnsRecords('a.com'));

    // The MX goes, a TXT comes, the A gets a new TTL.
    $plugin->setDnsRecords('a.com', [$records[0]->withTtl(3600), new DnsRecord('TXT', '_dmarc', 'v=DMARC1; p=none')]);
    $this->assertSame([
      'domain' => 'a.com',
      'rrid' => 'r2',
    ], array_intersect_key($this->query(7), ['domain' => 1, 'rrid' => 1]));
    $this->assertStringContainsString('dnsDeleteRecord', $this->call(7));
    $this->assertSame([
      'rrtype' => 'TXT',
      'rrhost' => '_dmarc',
      'rrvalue' => 'v=DMARC1; p=none',
    ], array_intersect_key($this->query(8), [
      'rrtype' => 1,
      'rrhost' => 1,
      'rrvalue' => 1,
      'rrttl' => 1,
      'rrdistance' => 1,
    ]));
    $this->assertStringContainsString('dnsUpdateRecord', $this->call(9));
    $this->assertSame([
      'rrid' => 'r1',
      'rrhost' => '',
      'rrvalue' => '192.0.2.1',
      'rrttl' => '3600',
    ], array_intersect_key($this->query(9), ['rrid' => 1, 'rrhost' => 1, 'rrvalue' => 1, 'rrttl' => 1]));
  }

}

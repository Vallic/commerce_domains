<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use GuzzleHttp\Psr7\Response;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\PaymentLinksInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Spaceship;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Spaceship provider.
 */
#[CoversClass(Spaceship::class)]
#[Group('commerce_domains')]
class SpaceshipTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'SPKEY', 'api_secret' => 'Sp-secret-9'];

  /**
   * Tests listings, with the full price as minimum for Atom premium names.
   */
  public function testBuildListing(): void {
    $plugin = $this->plugin(Spaceship::class, 'spaceship');
    $this->assertSame([
      'binPriceEnabled' => TRUE,
      'binPrice' => 1365,
      'minPriceEnabled' => TRUE,
      'minPrice' => 682,
    ], $plugin->buildListing(self::item('a.com', 1300)));
    $this->assertSame([
      'binPriceEnabled' => TRUE,
      'binPrice' => 1560,
      'minPriceEnabled' => TRUE,
      'minPrice' => 1560,
    ], $plugin->buildListing(self::item('p.com', 1300, ['atomPremium' => TRUE])));
    $this->assertNull($plugin->buildListing(self::item('free.com', 0)));
  }

  /**
   * Tests fetching a few names one by one, and reading money amounts.
   */
  public function testFetchFewNames(): void {
    $money = fn(string $amount) => ['amount' => $amount, 'currency' => 'USD'];
    $plugin = $this->plugin(Spaceship::class, 'spaceship', self::KEYS, [
      self::json([
        'name' => 'a.com',
        'status' => 'onSale',
        'binPriceEnabled' => TRUE,
        'binPrice' => $money('1500.00'),
        'minPriceEnabled' => TRUE,
        'minPrice' => $money('10.99'),
      ]),
      self::json(['detail' => 'Not found'], 404),
    ]);
    $this->assertSame([
      'a.com' => [
        'binPriceEnabled' => TRUE,
        'binPrice' => 1500,
        'minPriceEnabled' => TRUE,
        'minPrice' => 10.99,
        'status' => 'onSale',
      ],
    ], $plugin->fetchListings(['a.com', 'missing.com']));
    $this->assertSame('GET https://spaceship.dev/api/v1/sellerhub/domains/missing.com', $this->call(1));
  }

  /**
   * Tests that many names are read from the full list and filtered.
   */
  public function testFetchManyNames(): void {
    $names = array_map(fn($i) => "n$i.com", range(1, 25));
    $plugin = $this->plugin(Spaceship::class, 'spaceship', self::KEYS, [
      self::json([
        'items' => [['name' => 'n1.com', 'status' => 'onSale'], ['name' => 'other.com', 'status' => 'onSale']],
        'total' => 2,
      ]),
    ]);
    $this->assertSame(['n1.com'], array_keys($plugin->fetchListings($names)));
    $this->assertCount(1, $this->history);
  }

  /**
   * Tests creating, updating and removing listings.
   */
  public function testSaveAndDelete(): void {
    $plugin = $this->plugin(Spaceship::class, 'spaceship', self::KEYS, [
      self::json(['name' => 'xn--mnchen-3ya.de'], 201),
      self::json(['name' => 'a.com']),
      self::json(NULL, 204),
    ]);
    $listing = ['binPriceEnabled' => TRUE, 'binPrice' => 1000, 'minPriceEnabled' => TRUE, 'minPrice' => 500];
    $this->assertSame([], $plugin->saveListings([
      'xn--mnchen-3ya.de' => $listing,
      'a.com' => $listing,
    ], ['a.com' => ['status' => 'onSale']]));
    // New listings are created by their Unicode name.
    $this->assertSame([
      'name' => 'münchen.de',
      'binPriceEnabled' => TRUE,
      'binPrice' => ['amount' => '1000', 'currency' => 'USD'],
      'minPriceEnabled' => TRUE,
      'minPrice' => ['amount' => '500', 'currency' => 'USD'],
    ], $this->body(0));
    $this->assertSame('PATCH https://spaceship.dev/api/v1/sellerhub/domains/a.com', $this->call(1));
    $this->assertArrayNotHasKey('name', $this->body(1));

    $this->assertSame([], $plugin->deleteListings(['a.com']));
    $this->assertSame('DELETE https://spaceship.dev/api/v1/sellerhub/domains/a.com', $this->call(2));
  }

  /**
   * Tests reading registration details.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->plugin(Spaceship::class, 'spaceship', self::KEYS, [
      self::json([
        'name' => 'a.com',
        'registrationDate' => '2018-05-06T07:08:09.000Z',
        'expirationDate' => '2027-03-04T05:06:07.000Z',
        'autoRenew' => TRUE,
        'lifecycleStatus' => 'registered',
        'eppStatuses' => ['clientTransferProhibited', 'clientDeleteProhibited'],
      ]),
      self::json(['name' => 'b.com', 'eppStatuses' => []]),
      self::json(['detail' => 'Not found'], 404),
    ]);
    $this->assertInstanceOf(RegistrarInterface::class, $plugin);
    $registrations = $plugin->fetchRegistrations(['a.com', 'b.com', 'elsewhere.com']);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1804136767, TRUE, TRUE, 'registered', registered: 1525590489),
      'b.com' => new Registration('b.com', NULL, FALSE, NULL, NULL),
    ], $registrations);
    $this->assertSame('GET https://spaceship.dev/api/v1/domains/elsewhere.com', $this->call(2));
  }

  /**
   * Tests changing domains in the account.
   */
  public function testManagement(): void {
    $plugin = $this->registrar(Spaceship::class, 'spaceship', self::KEYS, [
      self::json([
        'name' => 'a.com',
        'nameservers' => ['provider' => 'custom', 'hosts' => ['NS1.Host.com', 'ns2.host.com']],
      ]),
      self::json(['name' => 'b.com', 'nameservers' => ['provider' => 'basic', 'hosts' => ['launch1.spaceship.net']]]),
      self::json(['provider' => 'custom', 'hosts' => ['ns1.x.com', 'ns2.x.com']]),
      self::json(['isLocked' => FALSE]),
      self::json(['isEnabled' => TRUE]),
      self::json(['authCode' => 'c0de', 'expires' => '2026-11-01T00:00:00Z']),
    ]);
    $this->assertInstanceOf(DomainManagementInterface::class, $plugin);
    $this->assertSame(['ns1.host.com', 'ns2.host.com'], $plugin->getNameservers('a.com'));
    $this->assertSame([], $plugin->getNameservers('b.com'), "Spaceship's own nameservers are not listed.");

    $plugin->setNameservers('a.com', ['ns1.x.com', 'ns2.x.com']);
    $this->assertSame('PUT https://spaceship.dev/api/v1/domains/a.com/nameservers', $this->call(2));
    $this->assertSame(['provider' => 'custom', 'hosts' => ['ns1.x.com', 'ns2.x.com']], $this->body(2));
    $plugin->setTransferLock('a.com', FALSE);
    $this->assertSame('PUT https://spaceship.dev/api/v1/domains/a.com/transfer/lock', $this->call(3));
    $this->assertSame(['isLocked' => FALSE], $this->body(3));
    $plugin->setAutoRenew('a.com', TRUE);
    $this->assertSame('PUT https://spaceship.dev/api/v1/domains/a.com/autorenew', $this->call(4));
    $this->assertSame(['isEnabled' => TRUE], $this->body(4));
    $this->assertSame('c0de', $plugin->getAuthCode('a.com'));
    $this->assertSame('GET https://spaceship.dev/api/v1/domains/a.com/transfer/auth-code', $this->call(5));
  }

  /**
   * Tests locks and display.
   */
  public function testReview(): void {
    $plugin = $this->plugin(Spaceship::class, 'spaceship');
    foreach (['saleProcessing', 'privateSale', 'leaseActive', 'sold', 'onSaleStopped'] as $status) {
      $this->assertSame($status, $plugin->lockReason(['status' => $status]));
    }
    $this->assertNull($plugin->lockReason(['status' => 'onSale']));
    $this->assertNull($plugin->lockReason(['status' => 'verifying']));
    $this->assertSame('$1,500', $plugin->describeValue('binPrice', 1500));
    $this->assertSame('$10.99', $plugin->describeValue('minPrice', 10.99));
  }

  /**
   * Tests reading DNS records and writing only what changes.
   */
  public function testDnsRecords(): void {
    $ours = self::json([
      'name' => 'a.com',
      'nameservers' => ['provider' => 'basic', 'hosts' => ['launch1.spaceship.net']],
    ]);
    $srv = [
      'type' => 'SRV',
      'name' => '@',
      'service' => '_sip',
      'protocol' => '_tcp',
      'priority' => 10,
      'weight' => 5,
      'port' => 5060,
      'target' => 'sip.a.com',
    ];
    $zone = self::json([
      'items' => [
      ['type' => 'A', 'name' => '@', 'address' => '192.0.2.1', 'ttl' => 3600, 'group' => ['type' => 'custom']],
      [
        'type' => 'MX',
        'name' => '@',
        'exchange' => 'mail.example.net',
        'preference' => 10,
        'ttl' => 3600,
        'group' => ['type' => 'custom'],
      ],
      [
        'type' => 'CAA',
        'name' => '@',
        'flag' => 0,
        'tag' => 'issue',
        'value' => 'letsencrypt.org',
        'ttl' => 3600,
        'group' => ['type' => 'custom'],
      ],
        $srv + ['ttl' => 3600, 'group' => ['type' => 'custom']],
      ['type' => 'A', 'name' => 'shop', 'address' => '192.0.2.8', 'ttl' => 3600, 'group' => ['type' => 'product']],
      ],
      'total' => 5,
    ]);
    $plugin = $this->registrar(Spaceship::class, 'spaceship', self::KEYS, [
      $ours,
      $zone,
      self::json(['name' => 'b.com', 'nameservers' => ['provider' => 'custom', 'hosts' => ['ns1.elsewhere.net']]]),
      $ours,
      $zone,
      new Response(204),
      new Response(204),
      new Response(204),
    ]);
    $records = $plugin->getDnsRecords('a.com');
    $this->assertCount(4, $records, "Spaceship's product records are left out.");
    $this->assertEquals(new DnsRecord('A', '@', '192.0.2.1', ttl: 3600), $records[0]);
    $this->assertEquals(new DnsRecord('MX', '@', 'mail.example.net', 10, 3600), $records[1]);
    $this->assertEquals(new DnsRecord('CAA', '@', '0 issue "letsencrypt.org"', ttl: 3600), $records[2]);
    $this->assertSame('SRV', $records[3]->type);
    $this->assertSame([], $records[0]->problems());
    $this->assertStringContainsString('dns/records/a.com', $this->call(1));
    $this->assertNull($plugin->getDnsRecords('b.com'), 'Nameservers elsewhere.');

    // The MX goes, a TXT comes, the A gets a new TTL; the SRV, a type not
    // offered here, stays untouched.
    $plugin->setDnsRecords('a.com', [
      $records[0]->withTtl(600),
      $records[2],
      $records[3],
      new DnsRecord('TXT', '_dmarc', 'v=DMARC1; p=none'),
    ]);
    $this->assertSame('DELETE https://spaceship.dev/api/v1/dns/records/a.com', $this->call(5));
    $mx = ['type' => 'MX', 'name' => '@', 'exchange' => 'mail.example.net', 'preference' => 10];
    $this->assertSame([$mx], $this->body(5));
    $this->assertSame('PUT https://spaceship.dev/api/v1/dns/records/a.com', $this->call(6));
    $this->assertSame([
      'force' => FALSE,
      'items' => [['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none', 'ttl' => 3600]],
    ], $this->body(6));
    $this->assertSame([
      'force' => FALSE,
      'items' => [['type' => 'A', 'name' => '@', 'address' => '192.0.2.1', 'ttl' => 600]],
    ], $this->body(7));
    $this->assertCount(8, $this->history);
  }

  /**
   * Tests SafePay payment links: made for a buyer, read back.
   */
  public function testPaymentLinks(): void {
    $transaction = fn(string $status) => self::json([
      'transactionId' => '550e8400-e29b-41d4-a716-446655440000',
      'status' => $status,
      'domainName' => 'a.com',
      'url' => [
        'buyer' => 'https://www.spaceship.com/sellerhub/safepay/buyer/550e8400',
        'seller' => 'https://www.spaceship.com/sellerhub/safepay/seller/550e8400',
      ],
    ], 201);
    $plugin = $this->plugin(Spaceship::class, 'spaceship', self::KEYS, [
      $transaction('pendingBuyerConfirm'),
      $transaction('purchased'),
      $transaction('expired'),
    ]);
    $this->assertInstanceOf(PaymentLinksInterface::class, $plugin);
    $this->assertSame(['USD'], $plugin->paymentLinkCurrencies());
    $link = $plugin->createPaymentLink('a.com', new Price('1500', 'USD'), 'buyer@example.com', ['buyer_fee' => 25]);
    $this->assertEquals(new PaymentLink('550e8400-e29b-41d4-a716-446655440000', 'https://www.spaceship.com/sellerhub/safepay/buyer/550e8400', PaymentLink::PENDING, 'pendingBuyerConfirm'), $link);
    $this->assertSame('POST https://spaceship.dev/api/v1/sellerhub/safepay-transactions', $this->call(0));
    $this->assertSame([
      'domainName' => 'a.com',
      'initiatedBy' => 'seller',
      'basePrice' => ['amount' => '1500.00', 'currency' => 'USD'],
      'type' => 'buyNow',
      'feePercentageShare' => ['seller' => 75, 'buyer' => 25],
      'buyerEmail' => 'buyer@example.com',
    ], $this->body(0));

    $this->assertSame(PaymentLink::PAID, $plugin->fetchPaymentLink($link->id)->state);
    $this->assertSame(100.0, $plugin->paymentLinkMinimum());
    $this->assertSame('GET https://spaceship.dev/api/v1/sellerhub/safepay-transactions/550e8400-e29b-41d4-a716-446655440000', $this->call(1));
    $this->assertSame(PaymentLink::ENDED, $plugin->fetchPaymentLink($link->id)->state);
  }

  /**
   * Tests a SafePay lease to own.
   */
  public function testLeasePaymentLink(): void {
    $plugin = $this->plugin(Spaceship::class, 'spaceship', self::KEYS, [
      self::json([
        'transactionId' => 't-1',
        'status' => 'pendingBuyerConfirm',
        'url' => ['buyer' => 'https://pay', 'seller' => 'https://sell'],
      ], 201),
    ]);
    $this->assertInstanceOf(PaymentLinksInterface::class, $plugin);
    $plugin->createPaymentLink('a.com', new Price('6000', 'USD'), 'buyer@example.com', [
      'lease_months' => 12,
      'down_payment' => 10,
    ]);
    $body = $this->body(0);
    $this->assertSame('leaseToOwn', $body['type']);
    $this->assertSame(['downPaymentPercentage' => 10, 'installmentPeriods' => 12], $body['ltoSettings']);
    $this->assertSame(['amount' => '6000.00', 'currency' => 'USD'], $body['basePrice']);
  }

}

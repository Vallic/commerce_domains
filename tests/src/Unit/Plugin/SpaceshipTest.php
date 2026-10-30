<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

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
    $this->assertSame(['binPriceEnabled' => TRUE, 'binPrice' => 1365, 'minPriceEnabled' => TRUE, 'minPrice' => 682], $plugin->buildListing(self::item('a.com', 1300)));
    $this->assertSame(['binPriceEnabled' => TRUE, 'binPrice' => 1560, 'minPriceEnabled' => TRUE, 'minPrice' => 1560], $plugin->buildListing(self::item('p.com', 1300, ['atomPremium' => TRUE])));
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
    $this->assertSame([], $plugin->saveListings(['xn--mnchen-3ya.de' => $listing, 'a.com' => $listing], ['a.com' => ['status' => 'onSale']]));
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

}

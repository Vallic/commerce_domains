<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\UnstoppableClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Unstoppable;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Unstoppable Domains provider.
 */
#[CoversClass(Unstoppable::class)]
#[CoversClass(UnstoppableClient::class)]
#[Group('commerce_domains')]
class UnstoppableTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'ud_mcp_Key1'];

  /**
   * Tests reading the portfolio page by page, then looking its domains up.
   *
   * Domains only added for sale, registered elsewhere, are in the portfolio
   * but not found by the lookup.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(Unstoppable::class, 'unstoppable', self::KEYS, [
      self::json([
        'domains' => [
          ['name' => 'A.com', 'expiresAt' => '2027-03-04T00:00:00.000Z', 'autoRenewal' => ['status' => 'active']],
          ['name' => 'added.com', 'expiresAt' => '2026-11-13T09:12:44.000Z', 'autoRenewal' => NULL],
        ],
        'pagination' => ['total' => 3, 'hasMore' => TRUE, 'nextOffset' => 100],
      ]),
      self::json([
        'domains' => [['name' => 'b.com', 'autoRenewal' => NULL, 'transferStatus' => 'pending']],
        'pagination' => ['total' => 3, 'hasMore' => FALSE, 'nextOffset' => NULL],
      ]),
      self::json([
        'domains' => [
          [
            'domain' => 'A.com',
            'found' => TRUE,
            'lifecycle' => ['expiresAt' => '2027-03-04T00:00:00.000Z', 'autoRenewal' => ['status' => 'active']],
          ],
          ['domain' => 'added.com', 'found' => FALSE, 'error' => 'You do not own domain "added.com"'],
          ['domain' => 'b.com', 'found' => TRUE, 'lifecycle' => ['autoRenewal' => NULL, 'transferStatus' => 'pending']],
        ],
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1804118400, NULL, TRUE),
      'b.com' => new Registration('b.com', NULL, NULL, FALSE, 'pending transfer'),
    ], $plugin->fetchRegistrations());
    $this->assertSame('POST https://api.unstoppabledomains.com/mcp/v1/actions/ud_portfolio_list', $this->call(0));
    $this->assertSame(['offset' => 0, 'limit' => 100, 'status' => 'all', 'registryType' => 'dns'], $this->body(0));
    $this->assertSame(100, $this->body(1)['offset']);
    $this->assertSame('POST https://api.unstoppabledomains.com/mcp/v1/actions/ud_domain_get', $this->call(2));
    $this->assertSame(['domains' => ['A.com', 'added.com', 'b.com']], $this->body(2));
    $this->assertSame('Bearer ud_mcp_Key1', $this->header(0, 'Authorization'));
  }

  /**
   * Tests a few names, which come with the transfer flag.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(Unstoppable::class, 'unstoppable', self::KEYS, [
      self::json([
        'domains' => [
          [
            'domain' => 'a.com',
            'found' => TRUE,
            'lifecycle' => ['expiresAt' => '2027-03-04T00:00:00.000Z', 'autoRenewal' => ['status' => 'cancelled']],
            'flags' => ['DNS_TRANSFER_OUT' => ['status' => 'DISABLED']],
          ],
          ['domain' => 'gone.com', 'found' => FALSE, 'error' => 'Not found'],
        ],
      ]),
    ]);
    $this->assertEquals(
      ['a.com' => new Registration('a.com', 1804118400, TRUE, FALSE)],
      $plugin->fetchRegistrations(['a.com', 'gone.com'])
    );
    $this->assertSame(['domains' => ['a.com', 'gone.com']], $this->body(0));
  }

  /**
   * Tests reading listings, and which are left alone.
   */
  public function testFetchListings(): void {
    $plugin = $this->plugin(Unstoppable::class, 'unstoppable', self::KEYS, [
      self::json([
        'domains' => [
          ['name' => 'a.com', 'listing' => ['id' => 7, 'price' => 129900, 'status' => 'open']],
          ['name' => 'b.com', 'listing' => ['id' => 8, 'price' => 50050, 'status' => 'pending']],
          ['name' => 'c.com', 'listing' => NULL],
        ],
        'pagination' => ['hasMore' => FALSE],
      ]),
    ]);
    $listings = $plugin->fetchListings();
    $this->assertSame([
      'a.com' => ['id' => 7, 'price' => 1299, 'status' => 'open'],
      'b.com' => ['id' => 8, 'price' => 500.5, 'status' => 'pending'],
    ], $listings);
    $this->assertSame('for-sale', $this->body(0)['status']);
    $this->assertNull($plugin->lockReason($listings['a.com']));
    $this->assertSame('pending', $plugin->lockReason($listings['b.com']));
    $this->assertSame([], $plugin->changedFields(['price' => 1299, 'minimum_offer' => 649], $listings['a.com']));
    $this->assertSame(['price' => 2000, 'minimum_offer' => 1000, 'offers' => TRUE], $plugin->buildListing(self::item('d.com', 2000)));
  }

  /**
   * Tests creating, updating and cancelling listings in batches.
   */
  public function testSaveAndDelete(): void {
    $plugin = $this->plugin(Unstoppable::class, 'unstoppable', self::KEYS, [
      self::json(['results' => [['domainName' => 'new.com', 'success' => TRUE, 'listingId' => 9, 'status' => 'open']]]),
      self::json(['results' => [['listingId' => 7, 'success' => FALSE, 'error' => 'Listing is locked']]]),
      self::json(['domains' => [['name' => 'old.com', 'listing' => ['id' => 7, 'price' => 100, 'status' => 'open']]]]),
      self::json(['results' => [['listingId' => 7, 'domainName' => 'old.com', 'success' => TRUE]]]),
    ]);
    $errors = $plugin->saveListings([
      'new.com' => ['price' => 1000, 'minimum_offer' => 500],
      'old.com' => ['price' => 2000, 'minimum_offer' => 1000],
    ], ['old.com' => ['id' => 7, 'price' => 1500, 'status' => 'open']]);
    $this->assertSame(['old.com' => 'Listing is locked'], $errors);

    $created = $this->body(0)['domains'][0];
    $this->assertSame(['domainName', 'priceInCents', 'listingSettings', 'expiresAt'], array_keys($created));
    $this->assertSame(100000, $created['priceInCents']);
    $this->assertSame(['isOfferFeatureEnabled' => TRUE, 'minOfferAmountInCents' => 50000], $created['listingSettings']);
    $this->assertGreaterThan(time() + 3000 * 86400, strtotime($created['expiresAt']), 'Listings run for years, not 90 days.');
    $this->assertSame('POST https://api.unstoppabledomains.com/mcp/v1/actions/ud_listing_update', $this->call(1));
    $this->assertSame(['id' => 7, 'priceInCents' => 200000], array_slice($this->body(1)['listings'][0], 0, 2));

    $this->assertSame([], $plugin->deleteListings(['old.com', 'unlisted.com']));
    $this->assertSame(['listingIds' => [7]], $this->body(3));
  }

}

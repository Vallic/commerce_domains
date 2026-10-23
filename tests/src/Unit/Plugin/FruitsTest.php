<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Fruits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the fruits provider.
 */
#[CoversClass(Fruits::class)]
#[Group('commerce_domains')]
class FruitsTest extends ProviderTestBase {

  protected const KEYS = ['client_id' => 'fr-client', 'client_secret' => 'Fr-secret-5'];

  /**
   * Tests listings in euro cents, as the net amount.
   */
  public function testBuildListing(): void {
    $plugin = $this->plugin(Fruits::class, 'fruits');
    $this->assertSame(['price' => 125900, 'currency' => 'eur', 'is_net' => TRUE], $plugin->buildListing(self::item('a.com', 1, ['prices' => ['EUR' => 1199]])));
    $premium = self::item('p.com', 1, ['prices' => ['EUR' => 1199], 'atomPremium' => TRUE]);
    $this->assertSame(143900, $plugin->buildListing($premium)['price']);
    $this->assertNull($plugin->buildListing(self::item('usd-only.com', 1000, ['prices' => ['USD' => 1000]])), 'No euro price, no listing.');
  }

  /**
   * Tests reading listings and what gets locked.
   */
  public function testFetchListings(): void {
    $domain = fn(string $name, ?string $archived = NULL) => [
      'name' => $name,
      'state' => 'verified',
      'product' => [
        'price' => ['amount' => 125900, 'currency' => 'EUR'],
        'is_net' => TRUE,
        'archived_at' => $archived,
      ],
    ];
    $plugin = $this->plugin(Fruits::class, 'fruits', self::KEYS, [
      self::json(['access_token' => 'tok', 'expires_in' => 7200]),
      self::json($domain('a.com')),
      self::json($domain('old.com', '2026-01-01T00:00:00Z')),
      // In the portfolio but not for sale.
      self::json(['name' => 'parked.com', 'state' => 'verified']),
    ]);
    $listings = $plugin->fetchListings(['a.com', 'old.com', 'parked.com']);
    $this->assertSame(['a.com', 'old.com'], array_keys($listings));
    $this->assertSame([
      'price' => 125900,
      'currency' => 'eur',
      'is_net' => TRUE,
      'archived' => FALSE,
      'state' => 'verified',
    ], $listings['a.com']);
    $this->assertNull($plugin->lockReason($listings['a.com']));
    $this->assertSame('archived', $plugin->lockReason($listings['old.com']));
    $this->assertSame('€1,259', $plugin->describeValue('price', 125900));
  }

  /**
   * Tests that adds and updates go out as one import.
   */
  public function testSaveListings(): void {
    $plugin = $this->plugin(Fruits::class, 'fruits', self::KEYS, [
      self::json(['access_token' => 'tok', 'expires_in' => 7200]),
      self::json(['id' => 'imp-1', 'state' => 'completed'], 201),
      self::json(['id' => 'imp-2', 'state' => 'failed'], 201),
    ]);
    $listing = ['price' => 100, 'currency' => 'eur', 'is_net' => TRUE];
    $this->assertSame([], $plugin->saveListings(['a.com' => $listing, 'b.com' => $listing], []));
    $this->assertSame([['name' => 'a.com'] + $listing, ['name' => 'b.com'] + $listing], $this->body(1)['source']['items']);

    $errors = $plugin->saveListings(['c.com' => $listing], []);
    $this->assertSame(['c.com' => 'fruits import imp-2 failed.'], $errors);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Unit;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Afternic;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Atom;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Dynadot;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Fruits;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Gname;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplacePluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\NameSilo;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Spaceship;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Unstoppable;
use Drupal\Tests\commerce_domains\Unit\Plugin\ProviderTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which listings each marketplace shows as a sale, and which locked.
 */
#[CoversClass(MarketplacePluginBase::class)]
#[CoversClass(Afternic::class)]
#[CoversClass(Atom::class)]
#[CoversClass(Dynadot::class)]
#[CoversClass(Fruits::class)]
#[CoversClass(Gname::class)]
#[CoversClass(NameSilo::class)]
#[CoversClass(Spaceship::class)]
#[CoversClass(Unstoppable::class)]
#[Group('commerce_domains')]
class SaleStatusTest extends ProviderTestBase {

  /**
   * Listings as each plugin's fetchListings() returns them.
   *
   * @return array<string, array{0: class-string, 1: string, 2: array, 3: string|null, 4: string|null}>
   *   Plugin class and ID, the listing, its sale status and its lock reason.
   */
  public static function listings(): array {
    $classes = [
      'afternic' => Afternic::class,
      'atom' => Atom::class,
      'dynadot' => Dynadot::class,
      'fruits' => Fruits::class,
      'gname' => Gname::class,
      'namesilo' => NameSilo::class,
      'spaceship' => Spaceship::class,
      'unstoppable' => Unstoppable::class,
    ];
    $premium = 'premium listing, managed by Atom';
    $paying = 'awaiting payment';
    $review = 'pending-admin-approval';
    // Per plugin: the listing, its sale status, its lock reason.
    $listings = [
      'afternic' => [
        'for sale' => [['listStatus' => 'ACTIVE'], NULL, NULL],
        'pending sale' => [['listStatus' => 'ON_HOLD'], 'ON_HOLD', 'ON_HOLD'],
        'sold' => [['listStatus' => 'SOLD'], 'SOLD', 'SOLD'],
      ],
      'atom' => [
        'for sale' => [['status' => 'Seller Approved', 'type' => 'standard'], NULL, NULL],
        'sold' => [['status' => 'Sold', 'type' => 'standard'], 'Sold', 'Sold'],
        'on hold' => [['status' => 'On Hold', 'type' => 'standard'], 'On Hold', 'On Hold'],
        'premium' => [['status' => 'Approved', 'type' => 'premium'], NULL, $premium],
        'premium sold' => [['status' => 'Purchased', 'type' => 'premium'], 'Purchased', $premium],
      ],
      'dynadot' => [
        'for sale' => [['pending_sale' => FALSE], NULL, NULL],
        'pending sale' => [['pending_sale' => TRUE], 'pending sale', 'pending sale'],
      ],
      'fruits' => [
        'archived' => [['archived' => TRUE, 'state' => 'verified'], NULL, 'archived'],
      ],
      'gname' => [
        'on sale' => [['id' => 1, 'price' => 100, 'ends' => ''], NULL, NULL],
      ],
      'namesilo' => [
        'active' => [['status' => 'active', 'sale_type' => 'Offer'], NULL, NULL],
        'awaiting payment' => [['status' => $paying, 'sale_type' => 'Offer'], $paying, $paying],
        'auction' => [['status' => 'active', 'sale_type' => 'Auction'], NULL, 'auction'],
        'unknown state' => [['status' => 'inactive', 'sale_type' => 'Offer'], NULL, 'inactive'],
      ],
      'spaceship' => [
        'on sale' => [['status' => 'onSale'], NULL, NULL],
        'stopped' => [['status' => 'onSaleStopped'], NULL, 'onSaleStopped'],
        'processing' => [['status' => 'saleProcessing'], 'saleProcessing', 'saleProcessing'],
        'SafePay' => [['status' => 'privateSale'], 'privateSale', 'privateSale'],
        'lease' => [['status' => 'leaseActive'], 'leaseActive', 'leaseActive'],
        'sold' => [['status' => 'sold'], 'sold', 'sold'],
      ],
      'unstoppable' => [
        'open' => [['status' => 'open'], NULL, NULL],
        'sale in progress' => [['status' => 'pending'], 'pending', 'pending'],
        'under review' => [['status' => $review], NULL, $review],
        'cancelled' => [['status' => 'canceled'], NULL, 'canceled'],
      ],
    ];
    $cases = [];
    foreach ($listings as $id => $plugin_cases) {
      foreach ($plugin_cases as $label => [$listing, $sale, $lock]) {
        $cases["$id $label"] = [$classes[$id], $id, $listing, $sale, $lock];
      }
    }
    return $cases;
  }

  /**
   * Tests a listing's sale status and lock reason.
   *
   * @param class-string<\Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase> $class
   *   The plugin class.
   * @param string $id
   *   The plugin ID.
   * @param array $listing
   *   The listing.
   * @param string|null $sale
   *   The sale status expected.
   * @param string|null $lock
   *   The lock reason expected.
   */
  #[DataProvider('listings')]
  public function testSaleStatus(string $class, string $id, array $listing, ?string $sale, ?string $lock): void {
    $plugin = $this->plugin($class, $id);
    $this->assertSame($sale, $plugin->saleStatus($listing));
    $this->assertSame($lock, $plugin->lockReason($listing));
  }

}

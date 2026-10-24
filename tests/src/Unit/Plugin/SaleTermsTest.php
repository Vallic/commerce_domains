<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Afternic;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Atom;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Dynadot;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Fruits;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Gname;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplacePluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\NameSilo;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Spaceship;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Unstoppable;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_price\Price;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how each marketplace lists a domain's sale terms.
 */
#[CoversClass(MarketplacePluginBase::class)]
#[CoversClass(CatalogItem::class)]
#[Group('commerce_domains')]
class SaleTermsTest extends ProviderTestBase {

  // Prices are listed as they are, so the numbers are easy to follow.
  protected const SETTINGS = ['price_factor' => 1.0, 'premium_price_factor' => 1.0];

  /**
   * Returns a $9,000 domain (€8,100) with the given terms.
   */
  protected static function domain(array $terms): CatalogItem {
    return self::item('a.com', 9000, ['terms' => new SaleTerms(...$terms)]);
  }

  /**
   * Returns the listing a marketplace builds.
   */
  protected function listing(string $class, string $id, CatalogItem $item): ?array {
    return $this->plugin($class, $id, self::SETTINGS)->buildListing($item);
  }

  /**
   * Tests converting amounts between currencies along with the price.
   */
  public function testAmounts(): void {
    $item = self::domain([]);
    $this->assertSame(900.0, $item->amount(new Price('900', 'USD'), 'USD'));
    $this->assertSame(1000.0, $item->amount(new Price('900', 'EUR'), 'USD'));
    $this->assertNull($item->amount(new Price('900', 'GBP'), 'USD'), 'No pound price to scale by.');
  }

  /**
   * Tests a domain that only takes offers.
   */
  public function testOffersOnly(): void {
    $item = self::domain(['buyNow' => FALSE]);
    $afternic = $this->listing(Afternic::class, 'afternic', $item);
    $this->assertSame(0, $afternic['buynowPrice']);
    $this->assertFalse($afternic['showLanderBuynowOption']);
    $this->assertTrue($afternic['showLanderMakeOfferOption']);
    $this->assertFalse($afternic['leasingEnabled']);
    $dynadot = $this->listing(Dynadot::class, 'dynadot', $item);
    $this->assertSame(['make_offer', FALSE], [$dynadot['listing_type'], $dynadot['installment']]);
    $spaceship = $this->listing(Spaceship::class, 'spaceship', $item);
    $this->assertSame([FALSE, TRUE], [$spaceship['binPriceEnabled'], $spaceship['minPriceEnabled']]);
    $namesilo = $this->listing(NameSilo::class, 'namesilo', $item);
    $this->assertSame([0, 4500, 0], [$namesilo['buy_now'], $namesilo['reserve'], $namesilo['payment_plan_months']]);
    $this->assertSame(0, $this->listing(Unstoppable::class, 'unstoppable', $item)['price']);
    // Marketplaces that only sell at a price don't list it.
    $this->assertNull($this->listing(Atom::class, 'atom', $item));
    $this->assertNull($this->listing(Fruits::class, 'fruits', $item));
    $this->assertNull($this->listing(Gname::class, 'gname', $item));
  }

  /**
   * Tests a domain that sells at its price only.
   */
  public function testNoOffers(): void {
    $item = self::domain(['offers' => FALSE]);
    $afternic = $this->listing(Afternic::class, 'afternic', self::item('a.com', 20000, ['terms' => new SaleTerms(offers: FALSE)]));
    $this->assertSame([20000, 20000], [$afternic['minbidPrice'] / 1_000_000, $afternic['floorPrice'] / 1_000_000]);
    $this->assertFalse($afternic['showLanderMakeOfferOption']);
    $dynadot = $this->listing(Dynadot::class, 'dynadot', $item);
    $this->assertSame(['buy_now', 9000], [$dynadot['listing_type'], $dynadot['minimum_offer']]);
    $this->assertFalse($this->listing(Spaceship::class, 'spaceship', $item)['minPriceEnabled']);
    $this->assertSame(9000, $this->listing(NameSilo::class, 'namesilo', $item)['reserve']);
    $this->assertFalse($this->listing(Unstoppable::class, 'unstoppable', $item)['offers']);
    $this->assertSame(['selling_price' => 9000], $this->listing(Atom::class, 'atom', $item));
  }

  /**
   * Tests that a domain sold neither way isn't listed anywhere.
   */
  public function testNotSold(): void {
    $item = self::domain(['buyNow' => FALSE, 'offers' => FALSE]);
    foreach ([Afternic::class, Dynadot::class, Spaceship::class, NameSilo::class, Unstoppable::class] as $class) {
      $this->assertNull($this->listing($class, strtolower((new \ReflectionClass($class))->getShortName()), $item), $class);
    }
  }

  /**
   * Tests a domain's own minimum offer, floor and lease term.
   */
  public function testOwnAmounts(): void {
    // Set in euros, half the euro price: half the dollar price too.
    $item = self::domain([
      'minimumOffer' => new Price('4050', 'EUR'),
      'floorPrice' => new Price('8100', 'EUR'),
      'leaseMonths' => 70,
    ]);
    $afternic = $this->listing(Afternic::class, 'afternic', $item);
    $this->assertSame([4500, 9000], [$afternic['minbidPrice'] / 1_000_000, $afternic['floorPrice'] / 1_000_000]);
    $this->assertSame(60, $afternic['maxLeasePeriod']);
    $dynadot = $this->listing(Dynadot::class, 'dynadot', $item);
    $this->assertSame([4500, 12], [$dynadot['minimum_offer'], $dynadot['maximum_installments']]);
    $this->assertSame(4500, $this->listing(Spaceship::class, 'spaceship', $item)['minPrice']);
    $namesilo = $this->listing(NameSilo::class, 'namesilo', $item);
    $this->assertSame([4500, 12], [$namesilo['reserve'], $namesilo['payment_plan_months']]);

    // The price factor raises the minimum offer like the price, up to it.
    $factored = $this->plugin(Unstoppable::class, 'unstoppable', ['price_factor' => 1.1])->buildListing($item);
    $this->assertSame([9900, 4950], [$factored['price'], $factored['minimum_offer']]);
    $above = self::domain(['minimumOffer' => new Price('20000', 'USD')]);
    $this->assertSame(9000, $this->listing(Unstoppable::class, 'unstoppable', $above)['minimum_offer']);

    // No lease, and no lease for a single payment.
    foreach ([0, 1] as $months) {
      $listing = $this->listing(Dynadot::class, 'dynadot', self::domain(['leaseMonths' => $months]));
      $this->assertFalse($listing['installment']);
    }
  }

}

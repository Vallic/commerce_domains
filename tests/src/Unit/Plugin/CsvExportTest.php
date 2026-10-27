<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Afternic;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Atom;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\BrandStore;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Daaz;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Dnx;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Dynadot;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Fruits;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingPluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\NameClub;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Sedo;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Spaceship;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Unstoppable;
use Drupal\commerce_domains\SaleTerms;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the CSV files providers write.
 */
#[CoversClass(Afternic::class)]
#[CoversClass(Atom::class)]
#[CoversClass(BrandStore::class)]
#[CoversClass(Daaz::class)]
#[CoversClass(Dnx::class)]
#[CoversClass(Dynadot::class)]
#[CoversClass(Fruits::class)]
#[CoversClass(ListingPluginBase::class)]
#[CoversClass(NameClub::class)]
#[CoversClass(Sedo::class)]
#[CoversClass(Spaceship::class)]
#[CoversClass(Unstoppable::class)]
#[Group('commerce_domains')]
class CsvExportTest extends ProviderTestBase {

  /**
   * Creates a CSV plugin.
   *
   * @param class-string<\Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase> $class
   *   The plugin class.
   * @param string $id
   *   The plugin ID.
   */
  protected function csv(string $class, string $id): CsvExportInterface&ProviderPluginBase {
    $plugin = $this->create($class, $id, [], []);
    $this->assertInstanceOf(CsvExportInterface::class, $plugin);
    return $plugin;
  }

  /**
   * Tests the plugins that only write files: no API, no sync.
   */
  public function testFileOnlyPlugins(): void {
    $defaults = [
      'brandstore' => [BrandStore::class, ['premium_price_factor' => 1.2, 'price_factor' => 1.1]],
      'daaz' => [Daaz::class, ['premium_price_factor' => 1.2, 'price_factor' => 1.05]],
      'dnx' => [Dnx::class, ['premium_price_factor' => 1.2, 'price_factor' => 1.05]],
      'nameclub' => [NameClub::class, ['premium_price_factor' => 1.2, 'price_factor' => 1.05]],
      // Sedo never gets Atom premium names.
      'sedo' => [Sedo::class, ['price_factor' => 1.1]],
    ];
    foreach ($defaults as $id => [$class, $configuration]) {
      $plugin = $this->csv($class, $id);
      $this->assertNotInstanceOf(MarketplaceInterface::class, $plugin, $id);
      $actual = $plugin->getConfiguration();
      ksort($actual);
      $this->assertSame($configuration, $actual, $id);
      $this->assertNull($plugin->getSetupError(), $id);
      $this->assertSame(['listings' => 'Listings'], $plugin->csvFiles(), $id);
      $this->assertEqualsCanonicalizing(array_keys($configuration), array_keys(array_intersect_key($plugin->buildConfigurationForm([], new FormState()), $configuration)), $id);
    }
  }

  /**
   * Tests Sedo's file.
   */
  public function testSedo(): void {
    $plugin = $this->csv(Sedo::class, 'sedo');
    $this->assertSame(['Domain Name', 'Selling Option', 'For Sale', 'Price', 'Minimum Price', 'Currency'], $plugin->csvHeader('listings'));
    // Euros: 900 × 1.1.
    $this->assertSame(['A.com', 'BUY_NOW', 'YES', 990, 495, 'EUR'], $plugin->csvRow(self::item('a.com'), 'listings'));
    $this->assertSame(['B.com', 'BUY_NOW', 'YES', 19800, 4950, 'EUR'], $plugin->csvRow(self::item('b.com', 20000), 'listings'));
    $this->assertSame(['A.com', 'BUY_NOW', 'YES', 990, 990, 'EUR'], $plugin->csvRow(self::item('a.com', 1000, ['terms' => new SaleTerms(offers: FALSE)]), 'listings'));
    $this->assertNull($plugin->csvRow(self::item('a.com', 1000, ['atomPremium' => TRUE]), 'listings'));
    $this->assertNull($plugin->csvRow(self::item('a.com', 1000, ['terms' => new SaleTerms(buyNow: FALSE)]), 'listings'));
    $this->assertNull($plugin->csvRow(self::item('a.com', 0), 'listings'));
  }

  /**
   * Tests the DaaZ, BrandStore and DNX files.
   */
  public function testPriceAndMinimumFiles(): void {
    $premium = self::item('p.com', 1000, ['atomPremium' => TRUE]);
    $no_offers = self::item('a.com', 1000, ['terms' => new SaleTerms(offers: FALSE)]);
    $offers_only = self::item('a.com', 1000, ['terms' => new SaleTerms(buyNow: FALSE)]);

    $daaz = $this->csv(Daaz::class, 'daaz');
    $this->assertSame(['Domain', 'Price', 'Min. Offer', 'Currency'], $daaz->csvHeader('listings'));
    $this->assertSame(['A.com', 1050, 525, 'USD'], $daaz->csvRow(self::item('a.com'), 'listings'));
    // Atom premium names take the premium factor, and no offers below it.
    $this->assertSame(['P.com', 1200, 1200, 'USD'], $daaz->csvRow($premium, 'listings'));
    $this->assertSame(['A.com', 1050, 1050, 'USD'], $daaz->csvRow($no_offers, 'listings'));
    $this->assertNull($daaz->csvRow($offers_only, 'listings'));

    $brandstore = $this->csv(BrandStore::class, 'brandstore');
    $this->assertSame(['domain', 'minPrice', 'buyItNowPrice'], $brandstore->csvHeader('listings'));
    $this->assertSame(['A.com', 550, 1100], $brandstore->csvRow(self::item('a.com'), 'listings'));
    $this->assertSame(['P.com', 1200, 1200], $brandstore->csvRow($premium, 'listings'));
    $this->assertSame(['A.com', 1100, 1100], $brandstore->csvRow($no_offers, 'listings'));
    $this->assertNull($brandstore->csvRow($offers_only, 'listings'));

    $dnx = $this->csv(Dnx::class, 'dnx');
    $this->assertSame(['Domain', 'Asking Price', 'Reserve Price', 'Min Offer'], $dnx->csvHeader('listings'));
    $this->assertSame(['A.com', 1050, 840, 525], $dnx->csvRow(self::item('a.com'), 'listings'));
    $this->assertSame(['P.com', 1200, 960, 1200], $dnx->csvRow($premium, 'listings'));
    $this->assertSame(['A.com', 1050, 1050, 1050], $dnx->csvRow($no_offers, 'listings'));
    $this->assertNull($dnx->csvRow($offers_only, 'listings'));
  }

  /**
   * Tests NameClub's file, which skips domains at Dynadot.
   */
  public function testNameClub(): void {
    $plugin = $this->csv(NameClub::class, 'nameclub');
    $this->assertSame('For-Sale Lander', $plugin->csvHeader('listings')[6]);
    $description = "<p>Short, sweet &amp; brandable.</p>\n<p>Second  line.</p>";
    $this->assertSame(
      ['A.com', 'USD', 1050, 'Yes', 525, 'Short, sweet & brandable Second line', 'Yes'],
      $plugin->csvRow(self::item('a.com', 1000, ['description' => $description, 'registrarPlugin' => 'namesilo']), 'listings'),
    );
    $this->assertSame(['A.com', 'USD', 1050, 'No', 1050, '', 'Yes'], $plugin->csvRow(self::item('a.com', 1000, ['terms' => new SaleTerms(offers: FALSE)]), 'listings'));
    $this->assertNull($plugin->csvRow(self::item('a.com', 1000, ['registrarPlugin' => 'dynadot']), 'listings'));
    $this->assertNull($plugin->csvRow(self::item('a.com', 1000, ['terms' => new SaleTerms(buyNow: FALSE)]), 'listings'));
  }

  /**
   * Tests the files of marketplaces with an API: the API listing, as a row.
   */
  public function testApiMarketplaces(): void {
    $afternic = $this->csv(Afternic::class, 'afternic');
    $this->assertCount(11, $afternic->csvHeader('listings'));
    $this->assertSame(['A.com', 1100, 880, 550, 'N', '', 'Custom Lander', 'Y', 'N', 'N', 'N'], $afternic->csvRow(self::item('a.com'), 'listings'));
    $this->assertSame(['B.com', 22000, 17600, 5500, 'Y', 24, 'Custom Lander', 'Y', 'Y', 'Y', 'N'], $afternic->csvRow(self::item('b.com', 20000), 'listings'));
    $this->assertNull($afternic->csvRow(self::item('a.com', 0), 'listings'));

    $spaceship = $this->csv(Spaceship::class, 'spaceship');
    $this->assertSame(['Domain', 'Offer Minimum', 'Buy Now Price', 'LTO Period', 'Downpayment %', 'For Sale Page'], $spaceship->csvHeader('listings'));
    $this->assertSame(['A.com', 525, 1050, 0, 25, 'on'], $spaceship->csvRow(self::item('a.com'), 'listings'));
    $this->assertSame(['B.com', 1575, 3150, 6, 25, 'on'], $spaceship->csvRow(self::item('b.com', 3000), 'listings'));
    $this->assertSame(['B.com', '', 3150, 6, 25, 'on'], $spaceship->csvRow(self::item('b.com', 3000, ['terms' => new SaleTerms(offers: FALSE)]), 'listings'));
    $this->assertSame(['B.com', 1575, '', 0, 25, 'on'], $spaceship->csvRow(self::item('b.com', 3000, ['terms' => new SaleTerms(buyNow: FALSE)]), 'listings'));

    $atom = $this->csv(Atom::class, 'atom');
    $this->assertSame(['Domain Name', 'Selling Price'], $atom->csvHeader('listings'));
    $this->assertSame(['A.com', 1050], $atom->csvRow(self::item('a.com'), 'listings'));
    $this->assertNull($atom->csvRow(self::item('a.com', 1000, ['atomPremium' => TRUE]), 'listings'));

    $fruits = $this->csv(Fruits::class, 'fruits');
    $this->assertSame(['Domain', 'Price', 'Currency', 'Pricing', 'Comment'], $fruits->csvHeader('listings'));
    $this->assertSame(['A.com', 945, 'EUR', 'net', ''], $fruits->csvRow(self::item('a.com'), 'listings'));

    $unstoppable = $this->csv(Unstoppable::class, 'unstoppable');
    $this->assertSame(['Domain', 'Price (USD)'], $unstoppable->csvHeader('listings'));
    $this->assertSame(['A.com', 1000], $unstoppable->csvRow(self::item('a.com', 1000, ['registrarPlugin' => 'unstoppable']), 'listings'));
    $this->assertNull($unstoppable->csvRow(self::item('a.com'), 'listings'), 'Only domains registered there.');
    $offers_only = self::item('a.com', 1000, [
      'registrarPlugin' => 'unstoppable',
      'terms' => new SaleTerms(buyNow: FALSE),
    ]);
    $this->assertNull($unstoppable->csvRow($offers_only, 'listings'));
  }

  /**
   * Tests Dynadot's two files: domains in the account, and the rest.
   */
  public function testDynadot(): void {
    $plugin = $this->csv(Dynadot::class, 'dynadot');
    $this->assertSame(['listings', 'external'], array_keys($plugin->csvFiles()));
    $this->assertCount(12, $plugin->csvHeader('listings'));
    $this->assertCount(7, $plugin->csvHeader('external'));

    $held = self::item('a.com', 1000, ['registrarPlugin' => 'dynadot', 'description' => 'Short. Sweet.']);
    $this->assertSame(['A.com', 'USD', '$1050', 'Yes', '$525', 'Business', '', 'No', 'Short Sweet', 'No', 0, 'No'], $plugin->csvRow($held, 'listings'));
    $this->assertNull($plugin->csvRow($held, 'external'));
    $big = self::item('b.com', 20000, ['registrarPlugin' => 'dynadot', 'terms' => new SaleTerms(offers: FALSE)]);
    $this->assertSame(['B.com', 'USD', '$21000', 'No', '$21000', 'Business', '', 'No', '', 'Yes', 12, 'No'], $plugin->csvRow($big, 'listings'));

    $elsewhere = self::item('c.com', 1000, ['registrarPlugin' => 'namesilo', 'terms' => new SaleTerms(buyNow: FALSE)]);
    $this->assertNull($plugin->csvRow($elsewhere, 'listings'));
    $this->assertSame(['C.com', 'USD', '', 'Yes', '$525', '', 'No'], $plugin->csvRow($elsewhere, 'external'));
  }

}

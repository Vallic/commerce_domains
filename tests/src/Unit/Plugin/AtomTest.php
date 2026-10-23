<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Atom;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Atom provider.
 */
#[CoversClass(Atom::class)]
#[Group('commerce_domains')]
class AtomTest extends ProviderTestBase {

  protected const KEYS = ['api_token' => 'atomTOKEN', 'user_id' => '4242'];

  /**
   * Tests that Atom premium names are left to Atom.
   */
  public function testPremiumNamesAreLeftAlone(): void {
    $plugin = $this->plugin(Atom::class, 'atom');
    $this->assertTrue($plugin->manages(self::item('a.com')));
    $this->assertFalse($plugin->manages(self::item('p.com', 1000, ['atomPremium' => TRUE])));
    $this->assertArrayNotHasKey('premium_price_factor', $plugin->getConfiguration());
    $this->assertArrayNotHasKey('premium_price_factor', $plugin->buildConfigurationForm([], new FormState()));
    $this->assertSame(['selling_price' => 1365], $plugin->buildListing(self::item('a.com', 1300)));
    $this->assertNull($plugin->buildListing(self::item('free.com', 0)));
  }

  /**
   * Tests reading all three listing types, and what gets locked.
   */
  public function testFetchListings(): void {
    $row = fn(string $name, int $price, string $status = 'Seller Approved') => [
      'domain_name' => $name,
      'status' => $status,
      'selling_price' => $price,
    ];
    $plugin = $this->plugin(Atom::class, 'atom', self::KEYS, [
      self::json([$row('A.com', 1365), $row('Sold.com', 900, 'Sold'), $row('Other.com', 5)]),
      self::json(['data' => [$row('Premium.com', 5000, 'Approved')]]),
      self::json([]),
    ]);
    $listings = $plugin->fetchListings(['a.com', 'sold.com', 'premium.com']);
    $this->assertSame([
      'a.com' => ['selling_price' => 1365, 'status' => 'Seller Approved', 'type' => 'standard'],
      'sold.com' => ['selling_price' => 900, 'status' => 'Sold', 'type' => 'standard'],
      'premium.com' => ['selling_price' => 5000, 'status' => 'Approved', 'type' => 'premium'],
    ], $listings);
    $this->assertSame(['standard', 'premium', 'geo'], array_map(fn($i) => $this->query($i)['type'], [0, 1, 2]));

    $this->assertNull($plugin->lockReason($listings['a.com']));
    $this->assertSame('Sold', $plugin->lockReason($listings['sold.com']));
    $this->assertSame('premium listing, managed by Atom', $plugin->lockReason($listings['premium.com']));
    $this->assertSame('On Hold', $plugin->lockReason(['status' => 'On Hold', 'type' => 'standard']));
  }

  /**
   * Tests adding, repricing and removing standard listings.
   */
  public function testSaveAndDelete(): void {
    $plugin = $this->plugin(Atom::class, 'atom', self::KEYS, [
      self::json(['success' => TRUE]),
      self::json(['success' => FALSE, 'message' => 'Domain already exists']),
      self::json(['success' => TRUE]),
    ]);
    $errors = $plugin->saveListings(['a.com' => ['selling_price' => 1300], 'b.com' => ['selling_price' => 900]], ['a.com' => ['selling_price' => 1]]);
    $this->assertSame('GET https://www.atom.com/api/marketplace/update-selling-price', $this->call(0));
    $this->assertSame('GET https://www.atom.com/api/marketplace/add-domain', $this->call(1));
    $this->assertSame(['b.com'], array_keys($errors));
    $this->assertStringContainsString('Domain already exists', $errors['b.com']);

    $this->assertSame([], $plugin->deleteListings(['a.com']));
    $this->assertSame('a.com', $this->query(2)['domain_name']);
    $this->assertSame('$1,300', $plugin->describeValue('selling_price', 1300));
  }

}

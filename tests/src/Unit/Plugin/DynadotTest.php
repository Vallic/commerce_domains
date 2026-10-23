<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Dynadot;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\Registration;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Dynadot provider.
 */
#[CoversClass(Dynadot::class)]
#[Group('commerce_domains')]
class DynadotTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'DynKey77', 'requests_per_minute' => 6000];

  /**
   * Tests that only domains registered at a Dynadot provider are managed.
   */
  public function testManages(): void {
    $plugin = $this->plugin(Dynadot::class, 'dynadot');
    $at_dynadot = self::item('a.com', 1000, ['registrar' => 'dynadot', 'registrarPlugin' => 'dynadot']);
    $this->assertTrue($plugin->manages($at_dynadot));
    $this->assertFalse($plugin->manages(self::item('b.com', 1000, ['registrar' => 'gname'])));
    $this->assertFalse($plugin->manages(self::item('c.com')));
  }

  /**
   * Tests listings: cents dropped, installments up to 12 months.
   */
  public function testBuildListing(): void {
    $plugin = $this->plugin(Dynadot::class, 'dynadot');
    $this->assertSame([
      'price' => 1363,
      'minimum_offer' => 681,
      'installment' => FALSE,
      'category' => 'business',
      'description' => 'A short name.',
    ], $plugin->buildListing(self::item('a.com', 1299, ['description' => " A short name. \n"])));

    $high = $plugin->buildListing(self::item('b.com', 60000));
    $this->assertSame(63000, $high['price']);
    $this->assertSame(15750, $high['minimum_offer']);
    $this->assertSame(12, $high['maximum_installments'], 'Dynadot allows 12 months at most.');
    $this->assertArrayNotHasKey('description', $high);
    $this->assertNull($plugin->buildListing(self::item('free.com', 0)));
  }

  /**
   * Tests reading the account, then prices of listings for sale.
   */
  public function testFetchListings(): void {
    $plugin = $this->plugin(Dynadot::class, 'dynadot', self::KEYS, [
      self::json([
        'ListDomainInfoResponse' => [
          'ResponseCode' => 0,
          'Status' => 'success',
          'MainDomains' => [
        ['Name' => 'A.com', 'isForSale' => 'yes'],
        ['Name' => 'b.com', 'isForSale' => 'yes'],
        ['Name' => 'c.com', 'isForSale' => 'no'],
        ['Name' => 'unrequested.com', 'isForSale' => 'yes'],
          ],
        ],
      ]),
      self::json([
        'GetListingsItemResponse' => [
          'ResponseCode' => 0,
          'Status' => 'success',
          'Listing' => ['Price' => '1363.00', 'Type' => 'BIN', 'PendingSaleLocked' => 'No'],
        ],
      ]),
      self::json(['GetListingsItemResponse' => ['ResponseCode' => -1, 'Status' => 'error', 'Error' => 'not found']]),
    ]);
    $listings = $plugin->fetchListings(['a.com', 'b.com', 'c.com']);
    $this->assertSame([
      'a.com' => ['price' => 1363, 'type' => 'BIN', 'pending_sale' => FALSE],
      // Listed but unreadable: unknown price, so the next push resets it.
      'b.com' => ['price' => NULL, 'type' => NULL, 'pending_sale' => FALSE],
    ], $listings);
    $this->assertCount(3, $this->history, 'Prices are only read for requested names that are for sale.');

    $this->assertSame([], $plugin->changedFields(['price' => 1363, 'minimum_offer' => 1], $listings['a.com']), 'Only the reported price is compared.');
    $this->assertSame(['price'], $plugin->changedFields(['price' => 1400], $listings['a.com']));
    $this->assertSame('pending sale', $plugin->lockReason(['pending_sale' => TRUE]));
    $this->assertNull($plugin->lockReason($listings['a.com']));
  }

  /**
   * Tests reading registration details from the account list.
   */
  public function testFetchRegistrations(): void {
    $domains = [
      [
        'Name' => 'A.com',
        'Expiration' => '1804136767000',
        'Locked' => 'yes',
        'RenewOption' => 'auto-renew',
        'Status' => 'active',
      ],
      ['Name' => 'b.com', 'Expiration' => '1804136767999', 'Locked' => 'no', 'RenewOption' => 'no renew option'],
      ['Name' => 'c.com'],
    ];
    $plugin = $this->plugin(Dynadot::class, 'dynadot', self::KEYS, [
      self::json(['ListDomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'MainDomains' => $domains]]),
    ]);
    $this->assertInstanceOf(RegistrarInterface::class, $plugin);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1804136767, TRUE, TRUE, 'active'),
      'b.com' => new Registration('b.com', 1804136767, FALSE, FALSE, NULL),
    ], $plugin->fetchRegistrations(['a.com', 'b.com']));
    $this->assertCount(1, $this->history, 'One account list serves every name.');
  }

  /**
   * Tests listing and taking domains off sale.
   */
  public function testSaveAndDelete(): void {
    $ok = self::json(['SetForSaleResponse' => ['ResponseCode' => 0, 'Status' => 'success']]);
    $plugin = $this->plugin(Dynadot::class, 'dynadot', self::KEYS, [$ok, $ok]);
    $listing = [
      'price' => 9000,
      'minimum_offer' => 4500,
      'installment' => TRUE,
      'category' => 'business',
      'maximum_installments' => 12,
      'description' => 'Nice',
    ];
    $this->assertSame([], $plugin->saveListings(['a.com' => $listing], []));
    $query = array_diff_key($this->query(0), ['key' => 1]);
    ksort($query);
    $this->assertSame([
      'category' => 'business',
      'command' => 'set_for_sale',
      'description' => 'Nice',
      'domains' => 'a.com',
      'for_sale_type' => 'marketplace',
      'installment' => 'Yes',
      'listing_type' => 'buy_now_and_make_offer',
      'maximum_installments' => '12',
      'minimum_offer' => '4500',
      'price' => '9000',
    ], $query);

    $this->assertSame([], $plugin->deleteListings(['a.com']));
    $this->assertSame('not_for_sale', $this->query(1)['for_sale_type']);
  }

  /**
   * Tests the API level setting and the single-variable credentials note.
   */
  public function testConfigurationForm(): void {
    $plugin = $this->plugin(Dynadot::class, 'dynadot');
    $form = $plugin->buildConfigurationForm([], new FormState());
    $this->assertSame([60, 600, 6000], array_keys($form['requests_per_minute']['#options']));
    $this->assertStringContainsString('<code>DYNADOT_API_KEY</code> environment variable.', (string) $form['credentials']['#markup']);
    $form_state = (new FormState())->setValues(['requests_per_minute' => '600', 'price_factor' => '1.05']);
    $plugin->submitConfigurationForm($form, $form_state);
    $this->assertSame(600, $plugin->getConfiguration()['requests_per_minute']);
    $this->assertSame('1363 characters', $plugin->describeValue('description', str_repeat('x', 1363)));
  }

}

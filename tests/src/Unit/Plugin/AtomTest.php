<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Appraisal;
use Drupal\commerce_domains\Clients\AtomClient;
use Drupal\commerce_domains\Clients\AtomRegistrarClient;
use Drupal\commerce_domains\ListingStats;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\AppraisalInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingStatsInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Atom;
use Drupal\Core\Form\FormState;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Atom provider.
 */
#[CoversClass(Atom::class)]
#[CoversClass(AtomRegistrarClient::class)]
#[CoversClass(AtomClient::class)]
#[Group('commerce_domains')]
class AtomTest extends ProviderTestBase {

  protected const KEYS = ['api_token' => 'atomTOKEN', 'user_id' => '4242'];

  /**
   * Tests appraising domains.
   */
  public function testAppraise(): void {
    $plugin = $this->plugin(Atom::class, 'atom', self::KEYS, [
      self::json([
        'domain_name' => 'a.com',
        'atom_appraisal' => 4999,
        'domain_score' => 7.7,
        'positive_signals' => ['Registered in 8 TLDs', 'Aged Domain (8.20 Years)'],
        'negative_signals' => ['5 Syllables'],
        'tld_taken_count' => 8,
      ]),
      self::json(['message' => 'Domain name is required']),
    ]);
    $this->assertInstanceOf(AppraisalInterface::class, $plugin);
    $this->assertEquals(new Appraisal('a.com', 4999, 7.7, 8, ['Registered in 8 TLDs', 'Aged Domain (8.20 Years)'], ['5 Syllables']), $plugin->appraise('a.com'));
    $this->assertSame('GET https://www.atom.com/api/marketplace/domain-appraisal', $this->call(0));
    $this->assertSame(['api_token' => 'atomTOKEN', 'user_id' => '4242', 'domain_name' => 'a.com'], $this->query(0));

    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('Domain name is required');
    $plugin->appraise('b.com');
  }

  /**
   * Tests reading listing analytics.
   */
  public function testFetchStats(): void {
    $plugin = $this->plugin(Atom::class, 'atom', self::KEYS, [
      self::json(['total_views' => 12, 'total_shortlists' => 2, 'total_offers' => 1]),
      self::json(['message' => 'Domain name should be there in the request']),
    ]);
    $this->assertInstanceOf(ListingStatsInterface::class, $plugin);
    $this->assertEquals(['a.com' => new ListingStats(12, NULL, 1, 2)], $plugin->fetchStats(['a.com', 'b.com']), 'A domain Atom refuses is left out.');
    $this->assertSame('GET https://www.atom.com/api/marketplace/domain-analytics', $this->call(0));
  }

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

  /**
   * Tests registrations, which need the separate registrar token.
   */
  public function testFetchRegistrations(): void {
    $keys = ['api_token' => 'at-Tok', 'user_id' => '42'];
    $plugin = $this->registrar(Atom::class, 'atom', $keys);
    try {
      $plugin->fetchRegistrations();
      $this->fail('The registrar token is needed.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Atom needs another credential for this: set ATOM_REGISTRAR_TOKEN.', $e->getMessage());
      $this->assertTrue($e->retryable);
    }

    $plugin = $this->registrar(Atom::class, 'atom', $keys + ['registrar_token' => 'reg-Tok'], [
      self::json([
        'success' => TRUE,
        'data' => [
          [
            'domain_name' => 'A.com',
            'status' => 'active',
            'created_date' => '2019-01-02 03:04:05',
            'expiry_date' => '2027-05-06 07:08:09',
            'auto_renew' => TRUE,
            'transfer_locked' => FALSE,
            'icann_transfer_lock' => TRUE,
          ],
        ],
        'pagination' => ['current_page' => 1, 'total_pages' => 2],
      ]),
      self::json([
        'success' => TRUE,
        'data' => [['domain_name' => 'b.com', 'transfer_locked' => FALSE]],
        'pagination' => ['total_pages' => 2],
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1809587289, TRUE, TRUE, 'active', registered: 1546398245),
      'b.com' => new Registration('b.com', NULL, FALSE),
    ], $plugin->fetchRegistrations());
    $this->assertSame('GET https://www.atom.com/api/registrar/v1/domains', $this->call(0));
    $this->assertSame('Bearer reg-Tok', $this->header(0, 'Authorization'));
    $this->assertSame(['page' => '2', 'size' => '100'], $this->query(1));
  }

  /**
   * Tests that the form names the optional registrar token.
   */
  public function testCredentialsHelp(): void {
    $form = $this->plugin(Atom::class, 'atom')->buildConfigurationForm([], new FormState());
    $this->assertStringContainsString('Optional: <code>ATOM_REGISTRAR_TOKEN</code>.', $form['credentials']['#markup']);
  }

}

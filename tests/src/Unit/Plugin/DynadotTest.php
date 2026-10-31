<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Dynadot;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
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
      'listing_type' => 'buy_now_and_make_offer',
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

    // The plan and a month's buffer must end before the domain expires.
    $expiring = fn(string $in) => self::item('c.com', 60000, ['expires' => strtotime($in)]);
    $this->assertSame(12, $plugin->buildListing($expiring('+2 years'))['maximum_installments']);
    $this->assertSame(5, $plugin->buildListing($expiring('+6 months +10 days'))['maximum_installments']);
    $short = $plugin->buildListing($expiring('+2 months +10 days'));
    $this->assertFalse($short['installment'], 'Under the two months a plan needs.');
    $this->assertArrayNotHasKey('maximum_installments', $short);
    $this->assertFalse($plugin->buildListing($expiring('-1 day'))['installment']);
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
      'a.com' => ['price' => 1363, 'type' => 'BIN', 'listing_type' => 'buy_now', 'pending_sale' => FALSE],
      // Listed but unreadable: unknown price, so the next push resets it.
      'b.com' => ['price' => NULL, 'type' => NULL, 'listing_type' => NULL, 'pending_sale' => FALSE],
    ], $listings);
    $this->assertCount(3, $this->history, 'Prices are only read for requested names that are for sale.');

    $wanted = ['listing_type' => 'buy_now', 'price' => 1363, 'minimum_offer' => 1];
    $this->assertSame([], $plugin->changedFields($wanted, $listings['a.com']), 'Only the reported price and type are compared.');
    $this->assertSame(['price'], $plugin->changedFields(['price' => 1400], $listings['a.com']));
    $this->assertSame(['listing_type'], $plugin->changedFields(['listing_type' => 'make_offer'], $listings['a.com']));
    $this->assertSame('pending sale', $plugin->lockReason(['pending_sale' => TRUE]));
    $this->assertNull($plugin->lockReason($listings['a.com']));
  }

  /**
   * Tests reusing listing reads in whole checks for a day.
   */
  public function testListingReadsAreKept(): void {
    $account = self::json([
      'ListDomainInfoResponse' => [
        'ResponseCode' => 0,
        'Status' => 'success',
        'MainDomains' => [['Name' => 'a.com', 'isForSale' => 'yes'], ['Name' => 'b.com', 'isForSale' => 'yes']],
      ],
    ]);
    $item = fn(string $price) => self::json([
      'GetListingsItemResponse' => [
        'ResponseCode' => 0,
        'Status' => 'success',
        'Listing' => ['Price' => $price, 'Type' => 'BIN', 'PendingSaleLocked' => 'No'],
      ],
    ]);
    $set = self::json(['SetForSaleResponse' => ['ResponseCode' => 0, 'Status' => 'success']]);
    $plugin = $this->plugin(Dynadot::class, 'dynadot', self::KEYS, [
      $account, $item('100.00'), $item('200.00'),
      // The next whole check reads the account only.
      $account,
      // A push, then the pushed listing is read again.
      $set, $account, $item('150.00'),
      // A few domains are read afresh.
      $account, $item('210.00'),
    ]);
    $this->assertSame(100, $plugin->fetchListings()['a.com']['price']);
    $this->assertCount(3, $this->history);
    $listings = $plugin->fetchListings();
    $this->assertSame([100, 200], [$listings['a.com']['price'], $listings['b.com']['price']]);
    $this->assertCount(4, $this->history, 'Kept reads need no request.');

    $plugin->saveListings(['a.com' => ['price' => 150, 'minimum_offer' => 75, 'installment' => FALSE]], []);
    $after_push = $plugin->fetchListings();
    $this->assertSame([150, 200], [$after_push['a.com']['price'], $after_push['b.com']['price']]);
    $this->assertCount(7, $this->history);
    $this->assertSame(210, $plugin->fetchListings(['b.com'])['b.com']['price']);
    $this->assertCount(9, $this->history);
  }

  /**
   * Tests reading registration details from the account list.
   */
  public function testFetchRegistrations(): void {
    $domains = [
      [
        'Name' => 'A.com',
        'Expiration' => '1804136767000',
        'Registration' => '1467969011000',
        'Locked' => 'yes',
        'RenewOption' => 'auto-renew',
        'Status' => 'active',
        'isForSale' => 'yes',
      ],
      [
        'Name' => 'b.com',
        'Expiration' => '1804136767999',
        'Locked' => 'no',
        'RenewOption' => 'no renew option',
        'isForSale' => 'no',
      ],
      ['Name' => 'c.com'],
    ];
    $plugin = $this->plugin(Dynadot::class, 'dynadot', self::KEYS, [
      self::json(['ListDomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'MainDomains' => $domains]]),
    ]);
    $this->assertInstanceOf(RegistrarInterface::class, $plugin);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1804136767, TRUE, TRUE, 'active', TRUE, 1467969011),
      'b.com' => new Registration('b.com', 1804136767, FALSE, FALSE, NULL, FALSE),
      'c.com' => new Registration('c.com', NULL),
    ], $plugin->fetchRegistrations());
    $this->assertCount(1, $this->history, 'One account list serves the whole account.');
  }

  /**
   * Tests reading a few domains one by one.
   */
  public function testFetchFewRegistrations(): void {
    $info = [
      'Name' => 'a.com',
      'Expiration' => '1804136767000',
      'Locked' => 'no',
      'RenewOption' => 'do not renew',
      'Status' => 'active',
    ];
    $plugin = $this->registrar(Dynadot::class, 'dynadot', self::KEYS, [
      self::json(['DomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'DomainInfo' => $info]]),
      self::json(['DomainInfoResponse' => ['ResponseCode' => -1, 'Status' => 'error', 'Error' => 'domain not found']]),
    ]);
    $expected = ['a.com' => new Registration('a.com', 1804136767, FALSE, FALSE, 'active')];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $query = array_diff_key($this->query(1), ['key' => TRUE]);
    $this->assertSame(['command' => 'domain_info', 'domain' => 'gone.com'], $query);
  }

  /**
   * Tests changing domains in the account.
   */
  public function testManagement(): void {
    $success = ['ResponseCode' => 0, 'Status' => 'success'];
    $ok = fn(string $response, array $extra = []) => self::json([$response => $success + $extra]);
    $nameservers = ['Host1' => 'NS2.Example.net', 'Host0' => 'ns1.example.net', 'NsName' => 'x'];
    $plugin = $this->registrar(Dynadot::class, 'dynadot', self::KEYS, [
      $ok('GetNsResponse', ['NsContent' => $nameservers]),
      $ok('SetNsResponse'),
      $ok('LockDomainResponse'),
      $ok('SetRenewOptionResponse'),
      $ok('GetTransferAuthCodeResponse', ['AuthCode' => 'c0de']),
    ]);
    $this->assertInstanceOf(DomainManagementInterface::class, $plugin);
    $this->assertSame(['ns1.example.net', 'ns2.example.net'], $plugin->getNameservers('a.com'));
    $plugin->setNameservers('a.com', ['ns1.host.com', 'ns2.host.com']);
    $query = array_diff_key($this->query(1), ['key' => TRUE]);
    $this->assertSame(['command' => 'set_ns', 'domain' => 'a.com', 'ns0' => 'ns1.host.com', 'ns1' => 'ns2.host.com'], $query);
    $plugin->setTransferLock('a.com', TRUE);
    $this->assertSame('lock_domain', $this->query(2)['command']);
    $plugin->setAutoRenew('a.com', FALSE);
    $this->assertSame('donot', $this->query(3)['renew_option']);
    $this->assertSame('c0de', $plugin->getAuthCode('a.com'));

    try {
      $plugin->setTransferLock('a.com', FALSE);
      $this->fail('api3 cannot unlock.');
    }
    catch (ProviderException $e) {
      $this->assertStringContainsString('unlock it in the Dynadot account', $e->getMessage());
    }
    $this->assertCount(5, $this->history, 'An unlock is refused without a call.');
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

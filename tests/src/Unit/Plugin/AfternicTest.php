<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Afternic;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplacePluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Afternic provider and the shared marketplace rules.
 */
#[CoversClass(Afternic::class)]
#[CoversClass(MarketplacePluginBase::class)]
#[CoversClass(ProviderPluginBase::class)]
#[Group('commerce_domains')]
class AfternicTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'KEY', 'api_secret' => 'Af-secret-1'];

  /**
   * Tests the defaults and the missing-keys error.
   */
  public function testSetup(): void {
    $plugin = $this->plugin(Afternic::class, 'afternic');
    $configuration = $plugin->getConfiguration();
    ksort($configuration);
    $this->assertSame(['api_key' => '', 'api_secret' => '', 'premium_price_factor' => 1.2, 'price_factor' => 1.1], $configuration);
    $this->assertSame('Afternic API credentials are missing: set AFTERNIC_API_KEY and AFTERNIC_API_SECRET.', $plugin->getSetupError());
    $this->assertNull($this->plugin(Afternic::class, 'afternic', self::KEYS)->getSetupError());

    // Calls without keys fail in a way that keeps queued work for later.
    try {
      $plugin->fetchListings(['a.com']);
      $this->fail('Fetching without keys should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
    }
  }

  /**
   * Tests the settings form.
   */
  public function testConfigurationForm(): void {
    $plugin = $this->plugin(Afternic::class, 'afternic');
    $form = $plugin->buildConfigurationForm([], new FormState());
    $this->assertSame(['credentials', 'price_factor', 'premium_price_factor'], array_keys($form));
    $this->assertStringContainsString('<code>AFTERNIC_API_KEY</code> and <code>AFTERNIC_API_SECRET</code>', (string) $form['credentials']['#markup']);

    $form_state = (new FormState())->setValues([
      'price_factor' => '1.15',
      'premium_price_factor' => '1.3',
      'api_key' => 'ignored',
    ]);
    $plugin->submitConfigurationForm($form, $form_state);
    $this->assertSame(1.15, $plugin->getConfiguration()['price_factor']);
    $this->assertSame(1.3, $plugin->getConfiguration()['premium_price_factor']);
    $this->assertSame('', $plugin->getConfiguration()['api_key'], 'Keys never come from the form.');
  }

  /**
   * Tests listings across the price tiers.
   */
  public function testBuildListing(): void {
    $plugin = $this->plugin(Afternic::class, 'afternic');
    $m = 1_000_000;

    $cheap = $plugin->buildListing(self::item('cheap.com', 1000));
    $this->assertSame([
      'buynowPrice' => 1100 * $m,
      'floorPrice' => 880 * $m,
      'minbidPrice' => 550 * $m,
      'leasingEnabled' => FALSE,
      'landingPage' => 'SALES_TYPE',
      'intakeStyle' => 'CUSTOM_LANDER',
      'showLanderBuynowOption' => TRUE,
      'showLanderLeaseToOwnOption' => FALSE,
      'showLanderMakeOfferOption' => FALSE,
      'hidden' => FALSE,
    ], $cheap);

    $mid = $plugin->buildListing(self::item('mid.com', 5000));
    $this->assertSame(5500 * $m, $mid['buynowPrice']);
    $this->assertTrue($mid['leasingEnabled']);
    $this->assertSame(6, $mid['maxLeasePeriod']);

    $high = $plugin->buildListing(self::item('high.com', 20000));
    $this->assertSame(24, $high['maxLeasePeriod']);
    $this->assertSame(5500 * $m, $high['minbidPrice'], 'A quarter from 10,000.');
    $this->assertTrue($high['showLanderMakeOfferOption']);

    $top = $plugin->buildListing(self::item('top.com', 60000));
    $this->assertSame(36, $top['maxLeasePeriod']);

    // Atom premium: its own factor, offers from the floor, no offer button.
    $premium = $plugin->buildListing(self::item('premium.com', 20000, ['atomPremium' => TRUE]));
    $this->assertSame(24000 * $m, $premium['buynowPrice']);
    $this->assertSame(19200 * $m, $premium['minbidPrice']);
    $this->assertFalse($premium['showLanderMakeOfferOption']);

    $this->assertSame(20 * $m, $plugin->buildListing(self::item('tiny.com', 10))['minbidPrice'], 'Afternic needs at least $20.');
    $this->assertNull($plugin->buildListing(self::item('free.com', 0)));
  }

  /**
   * Tests fetching, saving and removing listings.
   */
  public function testListingCalls(): void {
    $plugin = $this->plugin(Afternic::class, 'afternic', self::KEYS, [
      self::json(['domains' => [['name' => 'a.com', 'listStatus' => 'ACTIVE']]]),
      self::json(['a.com' => ['code' => 200], 'b.com' => ['code' => 200]]),
      self::json(['c.com' => ['code' => 200]]),
    ]);
    $listings = $plugin->fetchListings(['a.com', 'b.com']);
    $this->assertSame(['a.com' => ['name' => 'a.com', 'listStatus' => 'ACTIVE']], $listings);

    $wanted = $plugin->buildListing(self::item('a.com'));
    $current = [
      'a.com' => [
        'name' => 'a.com',
        'listStatus' => 'ACTIVE',
        'featured' => TRUE,
        'displayName' => 'A.com',
        'tld' => 'com',
        'buynowPrice' => 1,
      ],
    ];
    $this->assertSame([], $plugin->saveListings(['a.com' => $wanted, 'b.com' => $wanted], $current));
    $sent = array_column($this->body(1)['domains'], NULL, 'name');
    $this->assertTrue($sent['a.com']['featured'], 'Settings the catalog does not manage survive.');
    $this->assertSame('A.com', $sent['a.com']['displayName']);
    $this->assertArrayNotHasKey('listStatus', $sent['a.com'], 'Read-only fields are not sent back.');
    $this->assertArrayNotHasKey('tld', $sent['a.com']);
    $this->assertSame($wanted['buynowPrice'], $sent['a.com']['buynowPrice']);
    $this->assertArrayNotHasKey('featured', $sent['b.com']);

    $this->assertSame([], $plugin->deleteListings(['c.com']));
  }

  /**
   * Tests locks, comparison and display.
   */
  public function testReview(): void {
    $plugin = $this->plugin(Afternic::class, 'afternic');
    $this->assertSame('SOLD', $plugin->lockReason(['listStatus' => 'SOLD']));
    $this->assertSame('ON_HOLD', $plugin->lockReason(['listStatus' => 'ON_HOLD']));
    $this->assertNull($plugin->lockReason(['listStatus' => 'ACTIVE']));
    $wanted = ['buynowPrice' => 2, 'hidden' => FALSE, 'featured' => TRUE];
    $current = ['buynowPrice' => 1, 'featured' => TRUE];
    $this->assertSame(['buynowPrice', 'hidden'], $plugin->changedFields($wanted, $current));
    $this->assertSame('$1,429', $plugin->describeValue('buynowPrice', 1_429_000_000));
    $this->assertSame('true', $plugin->describeValue('hidden', TRUE));
  }

}

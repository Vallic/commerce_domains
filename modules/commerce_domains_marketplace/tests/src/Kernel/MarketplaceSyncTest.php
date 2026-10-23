<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests planning and pushing marketplace syncs.
 */
#[CoversClass(MarketplaceSync::class)]
#[CoversClass(SyncPlan::class)]
#[CoversClass(MarketplaceSettings::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceSyncTest extends MarketplaceKernelTestBase {

  /**
   * The sync service.
   *
   * @var \Drupal\commerce_domains_marketplace\MarketplaceSync
   */
  protected MarketplaceSync $sync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sync = $this->container->get(MarketplaceSync::class);

    $this->createDomain('Same.com', 1000);
    $this->createDomain('Repriced.com', 2000);
    $this->createDomain('New.com', 3000);
    $this->createDomain('Gone.com', 4000, [], ['in_stock' => FALSE]);
    $this->createDomain('Sold.com', 5000);
    $this->createDomain('Premium.com', 6000, ['atom_premium' => TRUE]);
    $this->setListings([
      'same.com' => ['price' => 1000],
      'repriced.com' => ['price' => 1],
      'gone.com' => ['price' => 4000],
      'sold.com' => ['price' => 5000, 'status' => 'sold'],
      'premium.com' => ['price' => 1],
      'foreign.com' => ['price' => 1],
      'deleted.com' => ['price' => 1],
    ]);
  }

  /**
   * Tests sorting a full sync into its buckets.
   */
  public function testPlan(): void {
    $plan = $this->sync->plan($this->createProvider('test'));
    $this->assertSame(['new.com' => ['price' => 3000]], $plan->create);
    $this->assertSame(['repriced.com' => ['price' => 2000]], $plan->update);
    $this->assertSame(['repriced.com' => ['price']], $plan->changes);
    $this->assertSame(1, $plan->unchanged);
    $this->assertSame(['gone.com'], $plan->delete);
    $this->assertSame(['sold.com' => 'sold'], $plan->locked);
    // Premium names are left to the marketplace; unknown names to whoever
    // listed them.
    $this->assertEqualsCanonicalizing(['premium.com', 'foreign.com', 'deleted.com'], $plan->unmanaged);
    $this->assertSame(4, $plan->forSale);
    $this->assertFalse($plan->isEmpty());
    $this->assertSame([['fetch', NULL]], $this->calls());
  }

  /**
   * Tests a sync of a few names, including one that left the catalog.
   */
  public function testPlanNames(): void {
    $plan = $this->sync->plan($this->createProvider('test'), ['repriced.com', 'gone.com'], ['deleted.com']);
    $this->assertSame([['fetch', ['repriced.com', 'gone.com', 'deleted.com']]], $this->calls());
    $this->assertSame(['repriced.com'], array_keys($plan->update));
    $this->assertSame(['gone.com', 'deleted.com'], $plan->delete);
    $this->assertSame([], $plan->unmanaged);
  }

  /**
   * Tests pushing a plan, with a refused listing.
   */
  public function testApply(): void {
    $provider = $this->createProvider('test');
    $this->container->get('state')->set('commerce_domains_test.reject', ['new.com']);
    $errors = $this->sync->sync($provider);
    $this->assertSame(['new.com' => 'Rejected by the test marketplace.'], $errors);
    $listings = $this->listings();
    $this->assertSame(2000, $listings['repriced.com']['price']);
    $this->assertArrayNotHasKey('gone.com', $listings);
    $this->assertArrayNotHasKey('new.com', $listings);
    $this->assertSame(1, $listings['premium.com']['price'], 'Unmanaged listings are untouched.');

    // The next sync only retries what is still different.
    $this->container->get('state')->delete('commerce_domains_test.reject');
    $plan = $this->sync->plan($provider);
    $this->assertSame(['new.com'], array_keys($plan->create));
    $this->assertSame([], $plan->update);
    $this->assertSame([], $plan->delete);
  }

  /**
   * Tests that removal can be turned off.
   */
  public function testRemovalTurnedOff(): void {
    $provider = $this->createProvider('test', TRUE, FALSE, FALSE);
    $this->sync->sync($provider);
    $this->assertArrayHasKey('gone.com', $this->listings());
    $this->assertNotContains('delete', array_column($this->calls(), 0));
  }

  /**
   * Tests providers that must not be pushed to.
   */
  public function testRefusedProviders(): void {
    $disabled = $this->createProvider('off', FALSE);
    // A dry run works while disabled.
    $plan = $this->sync->plan($disabled);
    try {
      $this->sync->apply($disabled, $plan);
      $this->fail('A disabled provider should not be pushed to.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Off is disabled.', $e->getMessage());
    }

    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('GNAME has no marketplace.');
    $this->sync->plan(Provider::load('gname'));
  }

}

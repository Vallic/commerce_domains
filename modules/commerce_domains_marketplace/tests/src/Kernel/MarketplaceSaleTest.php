<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent;
use Drupal\commerce_domains_marketplace\EventSubscriber\CatalogSubscriber;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncPlan;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a sale on a marketplace is a sale here too, once.
 */
#[CoversClass(MarketplaceSync::class)]
#[CoversClass(ListingRecorder::class)]
#[CoversClass(SyncPlan::class)]
#[CoversClass(CatalogSubscriber::class)]
#[CoversClass(SoldElsewhereEvent::class)]
#[CoversClass(Availability::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceSaleTest extends MarketplaceKernelTestBase {

  /**
   * The sync service.
   *
   * @var \Drupal\commerce_domains_marketplace\MarketplaceSync
   */
  protected MarketplaceSync $sync;

  /**
   * The domain a marketplace sells.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected ProductInterface $product;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sync = $this->container->get(MarketplaceSync::class);
    $this->product = $this->createDomain('Sold.com', 1000);
    $this->createDomain('Kept.com', 2000);
    $this->createProvider('selling');
    $this->createProvider('other', plugin: 'test_marketplace_other');
    // A buyer is paying for it on the selling marketplace.
    $this->setListings([
      'sold.com' => ['price' => 1000, 'status' => 'pending'],
      'kept.com' => ['price' => 2000],
    ]);
    $this->setListings(['sold.com' => ['price' => 1000], 'kept.com' => ['price' => 2000]], 'test_marketplace_other');
  }

  /**
   * Returns the domain's variation, as stored.
   */
  protected function variation(): ProductVariationInterface {
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    $variations = Product::load($this->product->id())->getVariations();
    return reset($variations);
  }

  /**
   * Returns the domain's row on a marketplace.
   */
  protected function row(string $provider, string $name = 'sold.com'): ?object {
    return $this->container->get(ListingRecorder::class)->rows($provider, [$name])[$name] ?? NULL;
  }

  /**
   * Takes the queued jobs and pushes each one.
   *
   * @return array<string, array>
   *   The payloads, keyed by provider.
   */
  protected function runJobs(): array {
    $payloads = [];
    foreach ($this->takeJobs() as $job) {
      $this->assertSame(SyncScheduler::JOB_TYPE, $job->getType());
      $payloads[$job->getPayload()['provider']] = $job->getPayload();
      $this->process($job);
    }
    return $payloads;
  }

  /**
   * Tests a sale found by a check: off sale here, removed elsewhere, once.
   */
  public function testSale(): void {
    $plan = $this->sync->plan(Provider::load('selling'));
    $this->assertSame(['sold.com' => 'pending'], $plan->sales);
    $this->assertSame(['sold.com'], $plan->newSales);
    $this->assertSame(['sold.com' => 'pending'], $plan->locked, 'The selling listing is left alone.');
    $this->assertFalse((bool) $this->variation()->get('in_stock')->value, 'Off sale here.');
    $this->assertSame([self::soldEvent('selling', 'sold.com', (int) $this->product->id(), 'pending')], $this->events());

    $row = $this->row('selling');
    $this->assertSame([ListingRecorder::SOLD, 'pending'], [$row->status, $row->note]);
    $this->assertGreaterThan(0, (int) $row->sold);

    // Both marketplaces push automatically, so the removal is queued; the
    // selling marketplace's job finds its listing locked.
    $payloads = $this->runJobs();
    $this->assertEqualsCanonicalizing(['selling', 'other'], array_keys($payloads));
    $this->assertContains('sold.com', $payloads['other']['names']);
    $this->assertArrayNotHasKey('sold.com', $this->listings('test_marketplace_other'));
    $this->assertArrayHasKey('kept.com', $this->listings('test_marketplace_other'));
    $this->assertSame(['price' => 1000, 'status' => 'pending'], $this->listings()['sold.com']);
    foreach ($this->calls() as [$operation, $names]) {
      $this->assertFalse($operation !== 'fetch' && in_array('sold.com', (array) $names, TRUE), "The selling listing gets no $operation.");
    }

    // Later checks find the same sale, also as it goes on to sold: nothing
    // more happens.
    $this->setListings(['sold.com' => ['price' => 1000, 'status' => 'sold'], 'kept.com' => ['price' => 2000]]);
    $plan = $this->sync->plan(Provider::load('selling'));
    $this->assertSame(['sold.com' => 'sold'], $plan->sales);
    $this->assertSame([], $plan->newSales);
    $this->assertSame([], $this->events());
    $this->assertSame([ListingRecorder::SOLD, 'sold'], [$this->row('selling')->status, $this->row('selling')->note]);
    $this->assertSame((int) $row->sold, (int) $this->row('selling')->sold, 'Still found when it was first found.');
    $this->assertSame([], $this->takeJobs(), 'No other change is queued.');
    // The other marketplace's row says it was removed for being off sale.
    $this->assertSame(ListingRecorder::UNLISTED, $this->row('other')->status);
  }

  /**
   * Tests a sale that falls through, and one that doesn't after all.
   */
  public function testRelist(): void {
    $this->sync->plan(Provider::load('selling'));
    $this->runJobs();
    $this->events();
    $sold = (int) $this->row('selling')->sold;

    // The owner puts it back in stock: the sale is over here, and the next
    // syncs list it again.
    $this->variation()->set('in_stock', TRUE)->save();
    $this->assertSame([ListingRecorder::LOCKED, 'pending'], [$this->row('selling')->status, $this->row('selling')->note]);
    $this->runJobs();
    $this->assertSame(['price' => 1000], $this->listings('test_marketplace_other')['sold.com']);
    $this->assertSame(ListingRecorder::LISTED, $this->row('other')->status);

    // The selling marketplace still shows the same sale: still left alone,
    // and not taken for a new one.
    $plan = $this->sync->plan(Provider::load('selling'));
    $this->assertSame([], $plan->newSales);
    $this->assertSame(['sold.com' => 'pending'], $plan->locked);
    $this->assertTrue((bool) $this->variation()->get('in_stock')->value);
    $this->assertSame([], $this->events());
    $this->assertSame(ListingRecorder::LOCKED, $this->row('selling')->status);
    $this->assertSame($sold, (int) $this->row('selling')->sold);

    // Then it shows the sale went through after all: that is new.
    $this->setListings(['sold.com' => ['price' => 1000, 'status' => 'sold'], 'kept.com' => ['price' => 2000]]);
    $plan = $this->sync->plan(Provider::load('selling'));
    $this->assertSame(['sold.com'], $plan->newSales);
    $this->assertFalse((bool) $this->variation()->get('in_stock')->value);
    $this->assertCount(1, $this->events());
    $this->assertSame(ListingRecorder::SOLD, $this->row('selling')->status);

    // Back in stock, and the marketplace lists it as for sale again: the
    // record of the sale ends, and the listing is the catalog's again.
    $this->variation()->set('in_stock', TRUE)->save();
    $this->setListings(['sold.com' => ['price' => 1], 'kept.com' => ['price' => 2000]]);
    $plan = $this->sync->plan(Provider::load('selling'));
    $this->assertSame([], $plan->sales);
    $this->assertSame(['sold.com' => ['price' => 1000]], $plan->update);
    $row = $this->row('selling');
    $this->assertSame([ListingRecorder::OUTDATED, NULL], [$row->status, $row->sold]);

    // A later sale is a sale again.
    $this->setListings(['sold.com' => ['price' => 1000, 'status' => 'pending'], 'kept.com' => ['price' => 2000]]);
    $this->assertSame(['sold.com'], $this->sync->plan(Provider::load('selling'))->newSales);
    $this->assertCount(1, $this->events());
  }

  /**
   * Tests that without automatic pushes, the next sync removes the listings.
   */
  public function testWithoutAutomaticPushes(): void {
    foreach (['selling', 'other'] as $id) {
      Provider::load($id)->setThirdPartySetting('commerce_domains_marketplace', 'auto_sync', FALSE)->save();
    }
    $this->sync->plan(Provider::load('selling'));
    $this->assertSame([], $this->takeJobs());
    $this->assertArrayHasKey('sold.com', $this->listings('test_marketplace_other'));

    $plan = $this->sync->plan(Provider::load('other'));
    $this->assertSame(['sold.com'], $plan->delete);
    $this->sync->apply(Provider::load('other'), $plan);
    $this->assertArrayNotHasKey('sold.com', $this->listings('test_marketplace_other'));
  }

  /**
   * Tests whose sales count: catalog domains, managed or not, and ours.
   */
  public function testWhichSalesCount(): void {
    // Left to the marketplace, like Atom premium names, but still ours.
    $premium = $this->createDomain('Premium.com', 3000, ['atom_premium' => TRUE]);
    $stopped = $this->createDomain('Stopped.com', 4000);
    $gone = $this->createDomain('Gone.com', 5000);
    $this->setListings([
      'premium.com' => ['price' => 3000, 'status' => 'sold'],
      // Locked, but no sale.
      'stopped.com' => ['price' => 4000, 'status' => 'stopped'],
      'gone.com' => ['price' => 5000],
      // Someone else's.
      'foreign.com' => ['price' => 1, 'status' => 'sold'],
    ]);
    $provider = Provider::load('selling');
    $this->sync->plan($provider);
    $this->events();

    // A product deleted since: the record says the name was ours.
    $gone->delete();
    $this->setListings([
      'premium.com' => ['price' => 3000, 'status' => 'sold'],
      'stopped.com' => ['price' => 4000, 'status' => 'stopped'],
      'gone.com' => ['price' => 5000, 'status' => 'sold'],
      'foreign.com' => ['price' => 1, 'status' => 'sold'],
    ]);
    $plan = $this->sync->plan($provider);
    $this->assertSame(['gone.com'], $plan->newSales);
    $sales = $plan->sales;
    ksort($sales);
    $this->assertSame(['gone.com' => 'sold', 'premium.com' => 'sold'], $sales);
    $this->assertSame([self::soldEvent('selling', 'gone.com', NULL, 'sold')], $this->events());
    $this->assertSame(ListingRecorder::SOLD, $this->row('selling', 'gone.com')->status);
    $this->assertNotContains('gone.com', $plan->delete, 'A sold listing is never removed.');

    $variations = Product::load($premium->id())->getVariations();
    $this->assertFalse((bool) reset($variations)->get('in_stock')->value);
    $this->assertSame(ListingRecorder::SOLD, $this->row('selling', 'premium.com')->status);
    $variations = Product::load($stopped->id())->getVariations();
    $this->assertTrue((bool) reset($variations)->get('in_stock')->value, 'A lock is not a sale.');
    $this->assertSame(ListingRecorder::LOCKED, $this->row('selling', 'stopped.com')->status);
    $this->assertNull($this->row('selling', 'foreign.com'), 'Not ours: no row.');

    // Whole checks keep finding the same sales; nothing more happens.
    $this->sync->plan($provider);
    $this->sync->plan($provider);
    $this->assertSame([], $this->events());
    $this->assertSame(ListingRecorder::SOLD, $this->row('selling', 'premium.com')->status);
  }

  /**
   * Tests a domain with several variations: all of them go off sale.
   */
  public function testVariations(): void {
    $second = ProductVariation::create([
      'type' => 'default',
      'sku' => 'sold-com-2',
      'price' => $this->product->getVariations()[0]->getPrice(),
      'status' => TRUE,
      'in_stock' => TRUE,
    ]);
    $second->save();
    $this->product->addVariation($second)->save();
    $this->sync->plan(Provider::load('selling'));
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    foreach (Product::load($this->product->id())->getVariations() as $variation) {
      $this->assertFalse((bool) $variation->get('in_stock')->value);
    }
    // Back on sale through one of them is enough.
    ProductVariation::load($second->id())->set('in_stock', TRUE)->save();
    $this->assertSame(ListingRecorder::LOCKED, $this->row('selling')->status);
  }

  /**
   * Tests that two syncs finding the same sale record it once.
   */
  public function testClaim(): void {
    $recorder = $this->container->get(ListingRecorder::class);
    $this->assertTrue($recorder->claimSale('selling', 'race.com', NULL, 'sold', ['status' => 'sold']));
    $this->assertFalse($recorder->claimSale('selling', 'race.com', NULL, 'sold', ['status' => 'sold']));
    $this->assertFalse($recorder->claimSale('selling', 'race.com', NULL, 'other', ['status' => 'other']), 'A sold row is on record, whatever the status.');

    $row = $this->row('selling', 'race.com');
    $this->assertFalse(ListingRecorder::isNewSale($row, 'sold'));
    $recorder->relisted('race.com');
    $row = $this->row('selling', 'race.com');
    $this->assertSame(ListingRecorder::LOCKED, $row->status);
    $this->assertFalse(ListingRecorder::isNewSale($row, 'sold'), 'The same sale, after the domain went back on sale.');
    $this->assertTrue(ListingRecorder::isNewSale($row, 'paid'), 'A new status then.');
    $this->assertTrue($recorder->claimSale('selling', 'race.com', NULL, 'paid', ['status' => 'paid']));
    $this->assertTrue(ListingRecorder::isNewSale(NULL, 'sold'));
  }

}

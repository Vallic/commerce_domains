<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\EventSubscriber\OrderSubscriber;
use Drupal\commerce_domains_marketplace\EventSubscriber\CatalogSubscriber;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a sale on the site gets the marketplace listings removed.
 */
#[CoversClass(OrderSubscriber::class)]
#[CoversClass(Availability::class)]
#[CoversClass(CatalogSubscriber::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SiteSaleTest extends MarketplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_number_pattern',
    'commerce_order',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    // The order receipt needs the date formats.
    $this->installConfig(['system', 'commerce_order']);
  }

  /**
   * Tests placing an order for a listed domain.
   */
  public function testPlacedOrder(): void {
    $this->createProvider('auto');
    $this->createProvider('manual', TRUE, FALSE, TRUE, 'test_marketplace_other');
    $product = $this->createDomain('Bought.com', 1000);
    // A second variation, e.g. another way to pay, goes off sale too.
    $other = ProductVariation::create([
      'type' => 'default',
      'sku' => 'bought-com-lease',
      'price' => $product->getVariations()[0]->getPrice(),
      'status' => TRUE,
      'in_stock' => TRUE,
    ]);
    $other->save();
    $product->addVariation($other)->save();
    $this->setListings(['bought.com' => ['price' => 1000]]);
    $this->setListings(['bought.com' => ['price' => 1000]], 'test_marketplace_other');
    $this->takeJobs();

    /** @var \Drupal\commerce_order\OrderItemStorageInterface $items */
    $items = $this->entityTypeManager->getStorage('commerce_order_item');
    $item = $items->createFromPurchasableEntity($product->getVariations()[0]);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'mail' => 'buyer@example.com',
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    $order->save();
    $order->getState()->applyTransitionById('place');
    $order->save();

    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    foreach (Product::load($product->id())->getVariations() as $variation) {
      $this->assertFalse((bool) $variation->get('in_stock')->value, 'Off sale: ' . $variation->getSku());
    }

    // The marketplace that pushes automatically gets the removal queued.
    $jobs = $this->takeJobs();
    $this->assertCount(1, $jobs);
    $this->assertSame(SyncScheduler::JOB_TYPE, $jobs[0]->getType());
    $this->assertSame('auto', $jobs[0]->getPayload()['provider']);
    $this->assertContains('bought.com', $jobs[0]->getPayload()['names']);
    $this->process($jobs[0]);
    $this->assertSame([], $this->listings());
    // The other one removes it on its next sync.
    $this->assertArrayHasKey('bought.com', $this->listings('test_marketplace_other'));
    $this->assertSame([], $this->events(), 'A sale here is no sale elsewhere.');
  }

}

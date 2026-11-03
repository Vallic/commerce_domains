<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_price\Price;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests syncing the fields a marketplace doesn't report back.
 */
#[CoversClass(MarketplaceSync::class)]
#[CoversClass(ListingRecorder::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class UnreportedFieldsTest extends MarketplaceKernelTestBase {

  protected const PLUGIN = 'test_partial_marketplace';

  /**
   * Tests sending once, comparing with what was sent, and failures.
   */
  public function testSent(): void {
    /** @var \Drupal\commerce_domains_marketplace\MarketplaceSync $sync */
    $sync = $this->container->get(MarketplaceSync::class);
    /** @var \Drupal\commerce_domains_marketplace\ListingRecorder $recorder */
    $recorder = $this->container->get(ListingRecorder::class);
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $provider = $this->createProvider('partial', plugin: self::PLUGIN);
    $product = $this->createDomain('Example.com', 1000);
    $state = $this->container->get('state');
    // Listed before anything was recorded, at the right price.
    $this->setListings(['example.com' => ['price' => 1000]], self::PLUGIN);

    // Nothing on record: sent once, then up to date.
    $plan = $sync->plan($provider);
    $this->assertSame(['example.com' => ['minimum_offer']], $plan->changes);
    $this->assertSame([], $sync->apply($provider, $plan));
    $this->assertSame(['example.com' => ['price' => 1000, 'minimum_offer' => 500]], $recorder->sent('partial', ['example.com']));
    $plan = $sync->plan($provider);
    $this->assertSame([], $plan->update);
    $this->assertSame(1, $plan->unchanged);

    // A change the marketplace can't show goes out, and says what changed.
    $variation = $product->getVariations()[0];
    $variation->set('sale_minimum_offer', new Price('400', 'USD'))->save();
    $plan = $sync->plan($provider);
    $this->assertSame(['example.com' => ['minimum_offer']], $plan->changes);
    $row = $recorder->rows('partial', ['example.com'])['example.com'];
    $this->assertSame(ListingRecorder::OUTDATED, $row->status);
    $this->assertSame('minimum_offer 500 → 400', $row->note);

    // A check or a refusal doesn't count as sent, so the next sync tries again.
    $state->set('commerce_domains_test.partial.reject', ['example.com']);
    $this->assertArrayHasKey('example.com', $sync->apply($provider, $plan));
    $still = $recorder->sent('partial', ['example.com']);
    $this->assertEquals(500, $still['example.com']['minimum_offer']);
    $state->delete('commerce_domains_test.partial.reject');
    $plan = $sync->plan($provider);
    $this->assertSame(['example.com' => ['minimum_offer']], $plan->changes);
    $this->assertSame([], $sync->apply($provider, $plan));
    $this->assertSame([], $sync->plan($provider)->update);
    $this->assertEquals(400, $recorder->sent('partial', ['example.com'])['example.com']['minimum_offer']);
    $this->assertSame(['price' => 1000, 'minimum_offer' => 400], $this->listings(self::PLUGIN)['example.com']);

    // Removed: nothing is on record as sent any more.
    $variation->set('in_stock', FALSE)->save();
    $this->assertSame([], $sync->apply($provider, $sync->plan($provider)));
    $this->assertSame([], $recorder->sent('partial', ['example.com']));
  }

  /**
   * Tests that marketplaces reporting everything don't use the record.
   */
  public function testReportedFields(): void {
    /** @var \Drupal\commerce_domains_marketplace\MarketplaceSync $sync */
    $sync = $this->container->get(MarketplaceSync::class);
    $provider = $this->createProvider('full');
    $this->createDomain('Example.com', 1000);
    $this->setListings(['example.com' => ['price' => 1000]]);
    $plan = $sync->plan($provider);
    $this->assertSame([], $plan->update, 'Nothing on record is no reason to send.');
    $this->assertSame(1, $plan->unchanged);
  }

  /**
   * Tests adding the column.
   */
  public function testUpdate(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropField(ListingRecorder::TABLE, 'sent');
    $this->container->get('module_handler')->loadInclude('commerce_domains_marketplace', 'install');
    commerce_domains_marketplace_update_11006();
    $this->assertTrue($schema->fieldExists(ListingRecorder::TABLE, 'sent'));
    commerce_domains_marketplace_update_11006();
  }

}

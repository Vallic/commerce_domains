<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\CsvExport;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests domains kept off some or all marketplaces, but for sale on the site.
 */
#[CoversClass(MarketplaceSync::class)]
#[CoversClass(ListingRecorder::class)]
#[CoversClass(CsvExport::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ListingChoiceTest extends MarketplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $this->createProvider('test');
    $this->createProvider('other', TRUE, TRUE, FALSE);
    Provider::create(['id' => 'daaz', 'label' => 'DaaZ', 'plugin' => 'daaz'])->save();

    $this->createDomain('Everywhere.com', 1000);
    $this->createDomain('Here.com', 2000, [], ['sale_marketplaces' => FALSE]);
    $this->createDomain('Some.com', 3000, [], ['sale_not_listed_on' => ['test', 'daaz']]);
    $this->setListings([
      'everywhere.com' => ['price' => 1000],
      'here.com' => ['price' => 2000],
      'some.com' => ['price' => 3000],
    ]);
  }

  /**
   * Tests that the sync removes the listings, and says why.
   */
  public function testSync(): void {
    $sync = $this->container->get(MarketplaceSync::class);
    $plan = $sync->plan(Provider::load('test'));
    $this->assertEqualsCanonicalizing(['here.com', 'some.com'], $plan->delete);
    $this->assertSame([], $plan->create);
    $this->assertSame(3, $plan->forSale, 'All three are still for sale.');

    $plan = $sync->plan(Provider::load('other'));
    $this->assertSame(['here.com'], $plan->delete, 'Some.com may be listed there.');

    $notes = $this->container->get('database')->select(ListingRecorder::TABLE, 'l')
      ->fields('l', ['domain', 'note'])
      ->condition('provider', 'other')
      ->execute()
      ->fetchAllKeyed();
    $this->assertSame('Not to be listed here; removal is turned off.', $notes['here.com']);

    $sync->apply(Provider::load('test'), $sync->plan(Provider::load('test')));
    $this->assertSame(['everywhere.com'], array_keys($this->listings()));
  }

  /**
   * Tests that CSV files leave the domains out.
   */
  public function testCsv(): void {
    $rows = $this->container->get(CsvExport::class)->rows(Provider::load('daaz'));
    $this->assertSame(['Everywhere.com'], array_column(array_slice($rows['listings'], 1), 0));
  }

}

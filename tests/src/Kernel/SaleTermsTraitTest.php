<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Plugin\Commerce\EntityTrait\SaleTerms as SaleTermsTrait;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_price\Price;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the sale terms trait and reading it into the catalog.
 */
#[CoversClass(SaleTermsTrait::class)]
#[CoversClass(SaleTerms::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SaleTermsTraitTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
  }

  /**
   * Tests that empty terms sell straight away, and set terms are read.
   */
  public function testTerms(): void {
    $this->createDomain('plain.com');
    $this->createDomain('offers.com', 9000, [], [
      'sale_buy_now' => FALSE,
      'sale_minimum_offer' => new Price('4000', 'USD'),
      'sale_lease_months' => 0,
    ]);
    $this->createDomain('fixed.com', 9000, [], [
      'sale_offers' => FALSE,
      'sale_floor_price' => new Price('8500', 'USD'),
      'sale_lease_months' => 12,
    ]);
    $items = $this->container->get('Drupal\commerce_domains\Catalog')->load();

    $this->assertEquals(new SaleTerms(), $items['plain.com']->terms, 'New variations sell straight away and take offers.');
    $this->assertEquals(new SaleTerms(buyNow: FALSE, minimumOffer: new Price('4000', 'USD'), leaseMonths: 0), $items['offers.com']->terms);
    $this->assertEquals(new SaleTerms(offers: FALSE, floorPrice: new Price('8500', 'USD'), leaseMonths: 12), $items['fixed.com']->terms);
  }

}

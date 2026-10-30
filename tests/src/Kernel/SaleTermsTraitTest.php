<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Plugin\Commerce\EntityTrait\SaleTerms as SaleTermsTrait;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
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

  /**
   * Tests keeping a domain off some or all marketplaces.
   */
  public function testMarketplaceChoice(): void {
    Provider::create(['id' => 'daaz', 'label' => 'DaaZ', 'plugin' => 'daaz'])->save();
    Provider::create(['id' => 'reg', 'label' => 'Registrar', 'plugin' => 'test_registrar'])->save();
    Provider::create(['id' => 'hover', 'label' => 'Hover'])->save();
    Provider::create(['id' => 'dan', 'label' => 'Dan', 'marketplace' => TRUE])->save();
    $this->createDomain('here.com', 1000, [], ['sale_marketplaces' => FALSE]);
    $this->createDomain('some.com', 1000, [], ['sale_not_listed_on' => ['daaz']]);
    $items = $this->container->get('Drupal\commerce_domains\Catalog')->load();

    $this->assertFalse($items['here.com']->terms->listsOn('daaz'));
    $this->assertFalse($items['some.com']->terms->listsOn('daaz'));
    $this->assertTrue($items['some.com']->terms->listsOn('afternic'));
    $this->assertSame(['daaz'], $items['some.com']->terms->notListedOn);
    $this->assertTrue($items['here.com']->forSale, 'Still for sale on the site.');

    // Only providers that list domains are offered, by API or by hand.
    $field = FieldConfig::loadByName('commerce_product_variation', 'default', 'sale_not_listed_on');
    $options = $this->container->get('plugin.manager.entity_reference_selection')->getSelectionHandler($field)->getReferenceableEntities();
    $this->assertEqualsCanonicalizing(['daaz', 'dan'], array_keys($options['commerce_domains_provider']));
  }

  /**
   * Tests adding the marketplace fields to sites that have the trait.
   */
  public function testUpdate(): void {
    // As the variation type form saves it.
    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $type */
    $type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load('default');
    $type->setTraits(['commerce_domains_sale_terms'])->save();
    foreach (['sale_marketplaces', 'sale_not_listed_on'] as $field_name) {
      FieldConfig::loadByName('commerce_product_variation', 'default', $field_name)->delete();
    }
    $this->container->get('module_handler')->loadInclude('commerce_domains', 'install');
    commerce_domains_update_11001();
    $field = FieldConfig::loadByName('commerce_product_variation', 'default', 'sale_not_listed_on');
    $this->assertNotNull($field);
    $this->assertSame('commerce_domains_listing:provider', $field->getSetting('handler'));
    $this->assertNotNull(FieldConfig::loadByName('commerce_product_variation', 'default', 'sale_marketplaces'));
    // Running it again changes nothing.
    commerce_domains_update_11001();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\PriceResolver;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests reading the catalog: availability, prices, registrars.
 */
#[CoversClass(Catalog::class)]
#[CoversClass(CatalogItem::class)]
#[CoversClass(Availability::class)]
#[CoversClass(PriceResolver::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class CatalogTest extends DomainsKernelTestBase {

  /**
   * The catalog service.
   *
   * @var \Drupal\commerce_domains\Catalog
   */
  protected Catalog $catalog;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->catalog = $this->container->get(Catalog::class);
    Provider::create(['id' => 'dynadot', 'label' => 'Dynadot', 'plugin' => 'dynadot'])->save();
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
  }

  /**
   * Tests what counts as for sale.
   */
  public function testAvailability(): void {
    $this->createDomain('Sale.com');
    $this->createDomain('Unpublished.com', 1000, ['status' => FALSE]);
    $this->createDomain('Inactive.com', 1000, [], ['status' => FALSE]);
    $this->createDomain('Sold.com', 1000, [], ['in_stock' => FALSE]);
    $internal = $this->createDomain('Internal.com', 1000, ['domain_internal' => TRUE]);

    $items = $this->catalog->load();
    $this->assertSame(['sale.com', 'unpublished.com', 'inactive.com', 'sold.com', 'internal.com'], array_keys($items));
    $this->assertSame([
      'sale.com' => TRUE,
      'unpublished.com' => FALSE,
      'inactive.com' => FALSE,
      'sold.com' => FALSE,
      'internal.com' => FALSE,
    ], array_map(fn(CatalogItem $item) => $item->forSale, $items));
    $this->assertSame('Sale.com', $items['sale.com']->title);

    // Internal domains stay unpublished, whoever publishes them.
    $this->assertFalse($internal->isPublished());
    $internal->setPublished()->save();
    $this->assertFalse($internal->isPublished());
    $internal->set('domain_internal', FALSE)->setPublished()->save();
    $this->assertTrue($this->availability()->isForSale($internal, $internal->getVariations()[0]));
  }

  /**
   * Tests that per-currency price fields win over the variation price.
   */
  public function testPrices(): void {
    // Other price fields are the site's business, not a price here.
    $this->createDomain('a.com', 1299, [], ['field_price_eur' => new Price('1199', 'EUR')]);
    $items = $this->catalog->load();
    $this->assertSame(['USD' => 1299.0], $items['a.com']->prices);
    $this->assertSame(1299.0, $items['a.com']->price('usd'));
    $this->assertSame(0.0, $items['a.com']->price('EUR'), 'No euro price is zero.');
  }

  /**
   * Tests registrars, flags and descriptions.
   */
  public function testItemDetails(): void {
    $this->createDomain('a.com', 1000, [
      'domain_registrar' => 'dynadot',
      'atom_premium' => TRUE,
      'domain_verified' => TRUE,
    ]);
    $this->createDomain('b.com', 1000, ['domain_registrar' => 'gname']);
    $this->createDomain('c.com');
    $items = $this->catalog->load();

    $this->assertSame(['dynadot', 'dynadot'], [$items['a.com']->registrar, $items['a.com']->registrarPlugin]);
    $this->assertSame(['gname', NULL], [$items['b.com']->registrar, $items['b.com']->registrarPlugin]);
    $this->assertSame([NULL, NULL], [$items['c.com']->registrar, $items['c.com']->registrarPlugin]);
    $this->assertTrue($items['a.com']->atomPremium);
    $this->assertFalse($items['b.com']->atomPremium);
    $this->assertTrue($items['a.com']->verified);
    $this->assertNull($items['a.com']->description, 'The test product type has no body field.');
  }

  /**
   * Tests loading by name, and the listing that wins when names repeat.
   */
  public function testLoadByName(): void {
    // An old, sold listing and a relist of the same name.
    $old = $this->createDomain('Relist.com', 900, [], ['in_stock' => FALSE]);
    $new = $this->createDomain('Relist.com', 1200);
    $this->createDomain('Other.com');

    $items = $this->catalog->load(['relist.com']);
    $this->assertSame(['relist.com'], array_keys($items));
    $this->assertTrue($items['relist.com']->forSale);
    $this->assertSame((int) $new->id(), $items['relist.com']->productId);
    $this->assertNotSame((int) $old->id(), $items['relist.com']->productId);

    $this->assertSame([], $this->catalog->load([]));
    $this->assertSame([], $this->catalog->load(['missing.com']));
  }

  /**
   * Tests that only product types with the domain trait are read.
   */
  public function testOtherProductTypes(): void {
    ProductType::create(['id' => 'merch', 'label' => 'Merch', 'variationTypes' => ['default']])->save();
    Product::create(['type' => 'merch', 'title' => 'shirt.com', 'stores' => [$this->store], 'status' => TRUE])->save();
    $this->createDomain('a.com');
    $this->assertSame(['default'], $this->catalog->productTypes());
    $this->assertSame(['a.com'], array_keys($this->catalog->load()));
  }

  /**
   * Returns the availability service.
   */
  protected function availability(): Availability {
    return $this->container->get(Availability::class);
  }

}

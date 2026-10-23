<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

/**
 * Base for kernel tests: a domain product type and helpers to fill it.
 */
abstract class DomainsKernelTestBase extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path',
    'commerce_product',
    'commerce_domains',
    'commerce_domains_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product');
    $this->installConfig(['commerce_product']);
    $this->container->get('commerce_price.currency_importer')->import('EUR');

    // The default product type sells domains, with Atom premium flags.
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    /** @var \Drupal\commerce_product\Entity\ProductTypeInterface $type */
    $type = $this->entityTypeManager->getStorage('commerce_product_type')->load('default');
    foreach (['commerce_domains_domain', 'commerce_domains_atom_premium'] as $trait_id) {
      $trait_manager->installTrait($trait_manager->createInstance($trait_id), 'commerce_product', 'default');
    }
    $type->setTraits(['commerce_domains_domain', 'commerce_domains_atom_premium'])->save();

    // Variations get stock (as commerce_escrow's trait adds) and a euro price.
    foreach (['in_stock' => 'boolean', 'field_price_eur' => 'commerce_price'] as $name => $field_type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product_variation',
        'type' => $field_type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product_variation',
        'bundle' => 'default',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * Creates a domain product with one variation.
   *
   * @param string $title
   *   The domain as buyers see it.
   * @param float $usd
   *   The variation price in US dollars.
   * @param array $product
   *   Extra product values.
   * @param array $variation
   *   Extra variation values.
   */
  protected function createDomain(string $title, float $usd = 1000, array $product = [], array $variation = []): ProductInterface {
    $variation = ProductVariation::create($variation + [
      'type' => 'default',
      'sku' => strtolower($title) . '-' . $this->randomMachineName(4),
      'price' => new Price((string) $usd, 'USD'),
      'status' => TRUE,
      'in_stock' => TRUE,
    ]);
    $variation->save();
    $entity = Product::create($product + [
      'type' => 'default',
      'title' => $title,
      'stores' => [$this->store],
      'variations' => [$variation],
      'status' => TRUE,
    ]);
    $entity->save();
    return $entity;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads domain products for providers, e.g. to sync marketplace listings.
 */
class Catalog {

  // The trait that makes a product type a domain.
  public const TRAIT = 'commerce_domains_domain';

  // Products loaded at once; the entity caches are emptied in between.
  protected const CHUNK_SIZE = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Availability $availability,
    protected PriceResolver $priceResolver,
  ) {}

  /**
   * Returns the product types that are domains.
   *
   * @return string[]
   *   Product type IDs.
   */
  public function productTypes(): array {
    $types = [];
    /** @var \Drupal\commerce_product\Entity\ProductTypeInterface $type */
    foreach ($this->entityTypeManager->getStorage('commerce_product_type')->loadMultiple() as $type) {
      if (in_array(self::TRAIT, $type->getTraits(), TRUE)) {
        $types[] = $type->id();
      }
    }
    return $types;
  }

  /**
   * Loads catalog domains, including those that are not for sale.
   *
   * @param string[]|null $names
   *   Normalized domain names to load, or NULL for the whole catalog.
   *
   * @return array<string, \Drupal\commerce_domains\CatalogItem>
   *   Items keyed by domain name. When several products share a name (an old
   *   listing and a relist), the one for sale wins.
   */
  public function load(?array $names = NULL): array {
    $types = $this->productTypes();
    if ($names === [] || !$types) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', $types, 'IN')->sort('product_id');
    if ($names !== NULL) {
      $query->condition('domain', array_values($names), 'IN');
    }
    $ids = $query->execute();

    $registrars = [];
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
      $registrars[$provider->id()] = $provider->getPluginId();
    }

    $items = [];
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($storage->loadMultiple($chunk) as $product) {
        $item = $this->item($product, $registrars);
        if ($item && (!isset($items[$item->name]) || (!$items[$item->name]->forSale && $item->forSale))) {
          $items[$item->name] = $item;
        }
      }
      $storage->resetCache($chunk);
      $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    }
    return $items;
  }

  /**
   * Builds the item for one product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The domain product.
   * @param array<string, string|null> $registrars
   *   Plugin IDs keyed by provider ID.
   */
  protected function item(ProductInterface $product, array $registrars): ?CatalogItem {
    $name = (string) $product->get('domain')->value ?: DomainName::normalize($product->getTitle());
    $variations = $product->getVariations();
    if ($name === '' || !$variations) {
      return NULL;
    }

    // A product with several variations is for sale if any of them is.
    $variation = reset($variations);
    $for_sale = FALSE;
    foreach ($variations as $candidate) {
      if ($this->availability->isForSale($product, $candidate)) {
        $variation = $candidate;
        $for_sale = TRUE;
        break;
      }
    }

    $registrar = $product->get('domain_registrar')->target_id ?: NULL;
    return new CatalogItem(
      name: $name,
      title: $product->getTitle(),
      productId: (int) $product->id(),
      forSale: $for_sale,
      prices: $this->priceResolver->prices($variation),
      registrar: $registrar,
      registrarPlugin: $registrar !== NULL ? ($registrars[$registrar] ?? NULL) : NULL,
      atomPremium: $product->hasField('atom_premium') && (bool) $product->get('atom_premium')->value,
      verified: (bool) $product->get('domain_verified')->value,
      description: $product->hasField('body') ? $product->get('body')->value : NULL,
    );
  }

}

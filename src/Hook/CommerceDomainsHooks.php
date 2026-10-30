<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;

/**
 * Hook implementations for Commerce Domains.
 */
class CommerceDomainsHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * Building entity types runs this, so the class needs no services: one
   * that led back to them (the exchanger reads entity types) would loop.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    // Only applies to product types with the domain trait.
    $entity_types['commerce_product']->addConstraint('CommerceDomainsDomainTitle');
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for commerce_product.
   *
   * Keeps internal domains unpublished, whatever else would publish them.
   */
  #[Hook('commerce_product_presave', order: Order::Last)]
  public function productPresave(ProductInterface $product): void {
    if (Availability::isInternal($product) && $product->isPublished()) {
      $product->setUnpublished();
    }
  }

}

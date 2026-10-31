<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
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
   * Implements hook_entity_form_display_alter().
   *
   * Core's timestamp widget saves a blank date as the time of saving, which
   * would give every domain saved an expiry or purchase date of today. The
   * domain's dates use the widget that keeps them empty, whatever the form
   * display was set to.
   */
  #[Hook('entity_form_display_alter')]
  public function entityFormDisplayAlter(EntityFormDisplayInterface $form_display, array $context): void {
    if ($context['entity_type'] !== 'commerce_product') {
      return;
    }
    foreach ($form_display->getComponents() as $name => $component) {
      if (str_starts_with($name, 'domain_') && ($component['type'] ?? NULL) === 'datetime_timestamp') {
        $form_display->setComponent($name, ['type' => 'commerce_domains_optional_timestamp'] + $component);
      }
    }
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

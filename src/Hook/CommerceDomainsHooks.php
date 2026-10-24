<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\Core\Hook\Attribute\Hook;

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

}

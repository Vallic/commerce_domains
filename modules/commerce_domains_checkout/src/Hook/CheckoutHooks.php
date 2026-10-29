<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\Hook;

use Drupal\commerce_domains_checkout\Form\DomainAddToCartForm;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Commerce Domains Checkout.
 */
class CheckoutHooks {

  /**
   * Implements hook_entity_type_build().
   */
  #[Hook('entity_type_build')]
  public function entityTypeBuild(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    $entity_types['commerce_order_item']->setFormClass('add_to_cart', DomainAddToCartForm::class);
  }

}

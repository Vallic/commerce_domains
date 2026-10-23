<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Decides whether a domain is for sale.
 *
 * Published product, active variation, and in stock where the variation has
 * an in_stock field (commerce_escrow's item trait adds one). Sites with
 * another stock model can replace this service.
 */
class Availability {

  /**
   * Whether a domain variation is for sale.
   */
  public function isForSale(ProductInterface $product, ProductVariationInterface $variation): bool {
    if (!$product->isPublished() || !$variation->isPublished()) {
      return FALSE;
    }
    return !$variation->hasField('in_stock') || (bool) $variation->get('in_stock')->value;
  }

}

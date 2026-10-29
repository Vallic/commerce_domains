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
 *
 * A sold domain goes out of stock, or, without stock, its variation is
 * unpublished; either way it is no longer for sale here, and the next
 * marketplace sync removes its listings. Putting it back in stock, or
 * publishing the variation again, puts it back on sale.
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

  /**
   * Takes a sold domain off sale.
   *
   * @return bool
   *   Whether the variation changed; it is saved when it did.
   */
  public function markSold(ProductVariationInterface $variation): bool {
    $field = $variation->hasField('in_stock') ? 'in_stock' : 'status';
    if (!$variation->get($field)->value) {
      return FALSE;
    }
    $variation->set($field, FALSE)->save();
    return TRUE;
  }

  /**
   * Takes every variation of a sold domain off sale.
   *
   * A domain sells once, whichever variation was bought; another variation
   * left for sale would keep it on the site and on the marketplaces.
   *
   * @return bool
   *   Whether any variation changed.
   */
  public function markProductSold(ProductInterface $product): bool {
    $changed = FALSE;
    foreach ($product->getVariations() as $variation) {
      if ($this->isForSale($product, $variation)) {
        $changed = $this->markSold($variation) || $changed;
      }
    }
    return $changed;
  }

}

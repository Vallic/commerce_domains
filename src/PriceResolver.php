<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Collects a variation's price in every currency it has one in.
 *
 * Starts from the variation price, then lets any other price field win for
 * its currency: sites that keep a price per currency (field_price_usd,
 * field_price_eur, as commerce_currency_resolver does) mean those.
 */
class PriceResolver {

  // Price fields that aren't the selling price.
  protected const IGNORED_FIELDS = ['price', 'list_price'];

  /**
   * Returns the prices of a variation.
   *
   * @return array<string, float>
   *   Amounts keyed by currency code.
   */
  public function prices(ProductVariationInterface $variation): array {
    $prices = [];
    if ($price = $variation->getPrice()) {
      $prices[$price->getCurrencyCode()] = (float) $price->getNumber();
    }
    foreach ($variation->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'commerce_price' || in_array($name, self::IGNORED_FIELDS, TRUE) || $variation->get($name)->isEmpty()) {
        continue;
      }
      /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
      $item = $variation->get($name)->first();
      $price = $item->toPrice();
      $prices[$price->getCurrencyCode()] = (float) $price->getNumber();
    }
    return $prices;
  }

}

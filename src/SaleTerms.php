<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * How a domain is sold, from the sale terms trait.
 *
 * Without the trait, or with its fields empty, the domain sells straight
 * away and takes offers, and the amounts are worked out from the price.
 */
final class SaleTerms {

  public function __construct(
    // Whether the domain sells at its price straight away.
    public readonly bool $buyNow = TRUE,
    // Whether offers below the price are taken.
    public readonly bool $offers = TRUE,
    // The lowest offer taken, or NULL to work it out from the price.
    public readonly ?Price $minimumOffer = NULL,
    // The lowest price a broker may agree to, or NULL to work it out.
    public readonly ?Price $floorPrice = NULL,
    // The longest lease in monthly payments, 0 for none, or NULL to work it
    // out from the price.
    public readonly ?int $leaseMonths = NULL,
  ) {}

  /**
   * Reads the terms of a variation.
   */
  public static function fromVariation(ProductVariationInterface $variation): self {
    $flag = fn(string $field) => !$variation->hasField($field) || $variation->get($field)->isEmpty() || (bool) $variation->get($field)->value;
    $price = function (string $field) use ($variation): ?Price {
      if (!$variation->hasField($field) || $variation->get($field)->isEmpty()) {
        return NULL;
      }
      /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
      $item = $variation->get($field)->first();
      return $item->toPrice();
    };
    $months = $variation->hasField('sale_lease_months') ? $variation->get('sale_lease_months')->value : NULL;
    return new self(
      buyNow: $flag('sale_buy_now'),
      offers: $flag('sale_offers'),
      minimumOffer: $price('sale_minimum_offer'),
      floorPrice: $price('sale_floor_price'),
      leaseMonths: $months === NULL || $months === '' ? NULL : (int) $months,
    );
  }

}

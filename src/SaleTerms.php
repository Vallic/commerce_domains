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
    // Whether marketplaces list the domain, or only this site sells it.
    public readonly bool $marketplaces = TRUE,
    // The IDs of providers that must not list the domain.
    public readonly array $notListedOn = [],
    // The percentage of the price paid at checkout for a lease on the site,
    // or NULL for the lease settings' default.
    public readonly ?int $leaseDownPayment = NULL,
  ) {}

  /**
   * Whether a provider may list the domain, by the seller's choice.
   */
  public function listsOn(string $provider_id): bool {
    return $this->marketplaces && !in_array($provider_id, $this->notListedOn, TRUE);
  }

  /**
   * Returns the minimum offer worked out from a price.
   *
   * Half the price below 10,000, a quarter above: the rule for domains whose
   * terms set no minimum, on the site and on marketplaces.
   */
  public static function defaultMinimumOffer(float $price): float {
    return $price < 10000 ? $price * 0.5 : $price * 0.25;
  }

  /**
   * Returns the lease-to-own term worked out from a price.
   *
   * Nothing below 2,000, then 6/12/24/36 months as the price grows: the rule
   * for domains whose terms set no term.
   *
   * @return int
   *   The term in months, 0 when the price is too low for lease-to-own.
   */
  public static function defaultLeaseMonths(float $price): int {
    return match (TRUE) {
      $price < 2000 => 0,
      $price < 8000 => 6,
      $price < 20000 => 12,
      $price < 50000 => 24,
      default => 36,
    };
  }

  /**
   * Returns the longest lease at a price: the domain's own term, or the rule.
   *
   * @return int
   *   The term in monthly payments; 0 for none, as one payment is no lease.
   */
  public function leaseMonthsAt(float $price): int {
    $months = $this->leaseMonths ?? self::defaultLeaseMonths($price);
    return $months >= 2 ? $months : 0;
  }

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
    $integer = function (string $field) use ($variation): ?int {
      $value = $variation->hasField($field) ? $variation->get($field)->value : NULL;
      return $value === NULL || $value === '' ? NULL : (int) $value;
    };
    return new self(
      buyNow: $flag('sale_buy_now'),
      offers: $flag('sale_offers'),
      minimumOffer: $price('sale_minimum_offer'),
      floorPrice: $price('sale_floor_price'),
      leaseMonths: $integer('sale_lease_months'),
      marketplaces: $flag('sale_marketplaces'),
      notListedOn: $variation->hasField('sale_not_listed_on') ? array_values(array_filter(array_column($variation->get('sale_not_listed_on')->getValue(), 'target_id'))) : [],
      leaseDownPayment: $integer('sale_lease_down_payment'),
    );
  }

}

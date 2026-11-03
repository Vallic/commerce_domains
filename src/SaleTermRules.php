<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * What a domain gets for the sale terms it leaves empty.
 *
 * Terms resolve from the general rules (Sale terms settings), to a provider's
 * own (its form), to the domain's own (its variation). This covers the first
 * two; SaleTerms holds the domain's, and takes them first. The price bands of
 * the lease rule are the same everywhere: see SaleTerms::LEASE_TERMS.
 */
final class SaleTermRules {

  // The settings, as they have always been.
  public const DEFAULTS = [
    'automatic_lease' => TRUE,
    'floor_percent' => 80,
    'minimum_offer_percent' => 50,
    'minimum_offer_percent_above' => 25,
    'minimum_offer_break' => 10000,
  ];

  public function __construct(
    // The lowest price a broker may agree to.
    public readonly int $floorPercent = self::DEFAULTS['floor_percent'],
    // The minimum offer below the break.
    public readonly int $minimumOfferPercent = self::DEFAULTS['minimum_offer_percent'],
    // The minimum offer from the break up.
    public readonly int $minimumOfferPercentAbove = self::DEFAULTS['minimum_offer_percent_above'],
    // The price where the minimum offer changes percentage.
    public readonly int $minimumOfferBreak = self::DEFAULTS['minimum_offer_break'],
    // Whether domains get a lease by their price.
    public readonly bool $automaticLease = self::DEFAULTS['automatic_lease'],
    // The longest lease worked out from the price, or NULL for no limit but
    // the price bands.
    public readonly ?int $leaseLimit = NULL,
  ) {}

  /**
   * Returns the general rules.
   */
  public static function general(): self {
    $config = \Drupal::hasService('config.factory') ? \Drupal::config('commerce_domains.settings') : NULL;
    $value = fn(string $key) => $config?->get($key) ?? self::DEFAULTS[$key];
    return new self(
      floorPercent: (int) $value('floor_percent'),
      minimumOfferPercent: (int) $value('minimum_offer_percent'),
      minimumOfferPercentAbove: (int) $value('minimum_offer_percent_above'),
      minimumOfferBreak: (int) $value('minimum_offer_break'),
      automaticLease: (bool) $value('automatic_lease'),
    );
  }

  /**
   * Returns these rules with a provider's own in their place.
   *
   * @param array<string, mixed> $terms
   *   Any of floor_percent, minimum_offer_percent,
   *   minimum_offer_percent_above, automatic_lease and lease_months. A term
   *   that is missing or NULL stays as it is here.
   */
  public function withOverrides(array $terms): self {
    return new self(
      floorPercent: (int) ($terms['floor_percent'] ?? $this->floorPercent),
      minimumOfferPercent: (int) ($terms['minimum_offer_percent'] ?? $this->minimumOfferPercent),
      minimumOfferPercentAbove: (int) ($terms['minimum_offer_percent_above'] ?? $this->minimumOfferPercentAbove),
      minimumOfferBreak: $this->minimumOfferBreak,
      automaticLease: (bool) ($terms['automatic_lease'] ?? $this->automaticLease),
      leaseLimit: isset($terms['lease_months']) ? (int) $terms['lease_months'] : $this->leaseLimit,
    );
  }

  /**
   * Returns the floor worked out from a price.
   */
  public function floor(float $price): float {
    return $price * $this->floorPercent / 100;
  }

  /**
   * Returns the share of a price the minimum offer is.
   */
  public function minimumOfferShare(float $price): float {
    return ($price < $this->minimumOfferBreak ? $this->minimumOfferPercent : $this->minimumOfferPercentAbove) / 100;
  }

  /**
   * Returns the minimum offer worked out from a price.
   */
  public function minimumOffer(float $price): float {
    return $price * $this->minimumOfferShare($price);
  }

  /**
   * Returns the longest lease worked out from a price, 0 for none.
   */
  public function leaseMonths(float $price): int {
    if (!$this->automaticLease) {
      return 0;
    }
    $months = SaleTerms::defaultLeaseMonths($price);
    return $this->leaseLimit === NULL ? $months : min($months, $this->leaseLimit);
  }

}

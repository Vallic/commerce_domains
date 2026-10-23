<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * A domain in the catalog, as providers see it.
 */
final class CatalogItem {

  public function __construct(
    // The domain in lowercase ASCII (IDNA) form, the way providers key it.
    public readonly string $name,
    // The product title: the name as buyers see it.
    public readonly string $title,
    public readonly int $productId,
    // Whether the domain is for sale; see Availability.
    public readonly bool $forSale,
    // Prices keyed by currency code; see PriceResolver.
    public readonly array $prices,
    // The registrar's provider ID, and that provider's plugin ID.
    public readonly ?string $registrar,
    public readonly ?string $registrarPlugin,
    public readonly bool $atomPremium,
    public readonly bool $verified,
    public readonly ?string $description,
  ) {}

  /**
   * Returns the price in a currency, or 0 when there is none.
   */
  public function price(string $currency): float {
    return $this->prices[strtoupper($currency)] ?? 0.0;
  }

}

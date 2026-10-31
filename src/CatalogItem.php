<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_price\Price;

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
    // How the domain is sold; see SaleTerms.
    public readonly SaleTerms $terms = new SaleTerms(),
    // When the registration runs out, as a Unix time; NULL when unknown.
    public readonly ?int $expires = NULL,
  ) {}

  /**
   * Returns the price in a currency, or 0 when there is none.
   */
  public function price(string $currency): float {
    return $this->prices[strtoupper($currency)] ?? 0.0;
  }

  /**
   * Returns an amount, such as a minimum offer, in another currency.
   *
   * Amounts move with the price: a minimum offer of half the euro price is
   * half the dollar price too.
   *
   * @return float|null
   *   The amount, or NULL without prices in both currencies.
   */
  public function amount(Price $amount, string $currency): ?float {
    $from = $amount->getCurrencyCode();
    $number = (float) $amount->getNumber();
    if ($from === strtoupper($currency)) {
      return $number;
    }
    $base = $this->price($from);
    $target = $this->price($currency);
    return $base > 0 && $target > 0 ? $number * $target / $base : NULL;
  }

}

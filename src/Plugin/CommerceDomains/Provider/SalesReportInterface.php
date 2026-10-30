<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * A marketplace that reports the account's sales: when, and for how much.
 *
 * Listings only show that a domain sold; the report fills in the sale.
 */
interface SalesReportInterface extends MarketplaceInterface {

  /**
   * Fetches the account's sales.
   *
   * @param string[]|null $names
   *   The domains, normalized, or NULL for every sale.
   *
   * @return array<string, \Drupal\commerce_domains\MarketplaceSale>
   *   The latest sale of each domain, keyed by domain.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function fetchSales(?array $names = NULL): array;

}

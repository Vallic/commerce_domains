<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * A marketplace that reports views, leads or offers per listing.
 */
interface ListingStatsInterface extends MarketplaceInterface {

  /**
   * Fetches the interest in some listings.
   *
   * @param string[] $names
   *   The domains, normalized; a few dozen at a time, as most marketplaces
   *   take one request each.
   *
   * @return array<string, \Drupal\commerce_domains\ListingStats>
   *   Stats keyed by domain. Domains the marketplace doesn't list, or refused
   *   alone, are left out.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the marketplace can't be read at all, e.g. an outage.
   */
  public function fetchStats(array $names): array;

}

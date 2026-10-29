<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\CatalogItem;

/**
 * Capability: lists catalog domains for sale on the provider's marketplace.
 *
 * Listings are flat arrays of the fields the catalog manages, usually named
 * after the provider's own API fields. A sync compares what buildListing()
 * wants with what fetchListings() reports and pushes the difference, so a
 * plugin never has to track what it sent before.
 */
interface MarketplaceInterface extends ProviderPluginInterface {

  /**
   * Returns whether the catalog manages a domain's listing here.
   *
   * Domains the plugin doesn't manage are never added, changed or removed,
   * even when they are listed: the provider or a person handles them.
   */
  public function manages(CatalogItem $item): bool;

  /**
   * Builds the listing a domain that is for sale should have.
   *
   * @return array|null
   *   The listing, or NULL when the domain must not be listed here (an
   *   existing listing is then removed).
   */
  public function buildListing(CatalogItem $item): ?array;

  /**
   * Fetches the provider's current listings.
   *
   * @param string[]|null $names
   *   The domains to fetch, or NULL for the whole portfolio.
   *
   * @return array<string, array>
   *   Listings keyed by domain name. Domains that are not listed are missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function fetchListings(?array $names = NULL): array;

  /**
   * Returns the managed fields where the provider differs from what we want.
   *
   * @return string[]
   *   The field names, empty when the listing is up to date.
   */
  public function changedFields(array $wanted, array $current): array;

  /**
   * Formats a listing value for people reviewing a sync.
   */
  public function describeValue(string $field, mixed $value): string;

  /**
   * Returns why a listing must be left alone, e.g. it is mid-sale.
   *
   * Listings that show a sale are always left alone, so this only needs the
   * other reasons, such as a listing the marketplace curates itself.
   *
   * @return string|null
   *   The listing's state, or NULL when it may be changed.
   */
  public function lockReason(array $current): ?string;

  /**
   * Returns the sale a listing shows: the domain sold, or a sale under way.
   *
   * A sync that finds one takes the domain off sale on the site, which gets
   * its listings elsewhere removed, and leaves this listing alone.
   *
   * @return string|null
   *   The marketplace's status for the sale, e.g. "SOLD", or NULL when the
   *   listing shows none.
   */
  public function saleStatus(array $current): ?string;

  /**
   * Creates or updates listings.
   *
   * @param array<string, array> $listings
   *   The wanted listings, keyed by domain name.
   * @param array<string, array> $current
   *   The current listing for each name that is already listed, so settings
   *   the catalog does not manage survive the update.
   *
   * @return array<string, string>
   *   Error messages keyed by domain name; names that succeeded are missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function saveListings(array $listings, array $current): array;

  /**
   * Removes listings.
   *
   * @param string[] $names
   *   The domain names.
   *
   * @return array<string, string>
   *   Error messages keyed by domain name; names that succeeded are missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function deleteListings(array $names): array;

}

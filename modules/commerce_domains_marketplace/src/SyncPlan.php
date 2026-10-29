<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

/**
 * What a sync would change on one marketplace.
 */
final class SyncPlan {

  /**
   * Listings to add, keyed by domain name.
   *
   * @var array<string, array>
   */
  public array $create = [];

  /**
   * Listings to update, keyed by domain name.
   *
   * @var array<string, array>
   */
  public array $update = [];

  /**
   * The fields that differ for each name in $update.
   *
   * @var array<string, string[]>
   */
  public array $changes = [];

  /**
   * Listed catalog domains that are no longer for sale.
   *
   * @var string[]
   */
  public array $delete = [];

  /**
   * Listings left alone, e.g. mid-sale, with the reason, keyed by name.
   *
   * @var array<string, string>
   */
  public array $locked = [];

  /**
   * Our listings that show a sale, with the marketplace's status for it.
   *
   * Catalog domains, managed or not, and domains that just left the catalog.
   * They are locked too.
   *
   * @var array<string, string>
   */
  public array $sales = [];

  /**
   * The sales in $sales this check found first, and took off sale here.
   *
   * @var string[]
   */
  public array $newSales = [];

  /**
   * The sales in $sales a dry run found first, left for the next check.
   *
   * They are still for sale here.
   *
   * @var string[]
   */
  public array $salesToRecord = [];

  /**
   * The catalog domains that were for sale here when the check began.
   *
   * @var array<string, true>
   */
  public array $available = [];

  /**
   * Listed names the sync never touches.
   *
   * Names the catalog doesn't know, or that the plugin leaves to the network.
   *
   * @var string[]
   */
  public array $unmanaged = [];

  /**
   * How many listings already match.
   */
  public int $unchanged = 0;

  /**
   * How many catalog domains are for sale and managed on this marketplace.
   */
  public int $forSale = 0;

  /**
   * The network's current listings, keyed by domain name.
   *
   * @var array<string, array>
   */
  public array $current = [];

  /**
   * The listings managed domains should have, keyed by domain name.
   *
   * Domains that should not be listed are missing.
   *
   * @var array<string, array>
   */
  public array $wanted = [];

  /**
   * The catalog domains the sync checked, with their product IDs.
   *
   * @var array<string, int>
   */
  public array $catalog = [];

  /**
   * The catalog domains this marketplace's listings are managed for.
   *
   * @var string[]
   */
  public array $managed = [];

  /**
   * Domains that just left the catalog, and are not in $managed.
   *
   * @var string[]
   */
  public array $removed = [];

  /**
   * Domains for sale that the seller keeps off this marketplace.
   *
   * @var string[]
   */
  public array $excluded = [];

  /**
   * Whether the sync covered the whole catalog and portfolio.
   */
  public bool $full = FALSE;

  /**
   * Whether the sync has nothing to push or remove.
   */
  public function isEmpty(): bool {
    return !$this->create && !$this->update && !$this->delete;
  }

}

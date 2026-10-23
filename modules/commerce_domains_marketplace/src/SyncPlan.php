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
   * Listings left alone because the network is selling them, keyed by name.
   *
   * @var array<string, string>
   */
  public array $locked = [];

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
   * Whether the sync has nothing to push or remove.
   */
  public function isEmpty(): bool {
    return !$this->create && !$this->update && !$this->delete;
  }

}

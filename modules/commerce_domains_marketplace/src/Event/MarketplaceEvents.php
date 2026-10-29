<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Event;

/**
 * The events marketplace syncs dispatch, e.g. to tell the domain's owner.
 */
final class MarketplaceEvents {

  /**
   * A marketplace shows a catalog domain sold, or a sale under way.
   *
   * Dispatched once per sale, after the domain went off sale on the site and
   * the removal of its other listings was scheduled.
   *
   * @Event
   *
   * @see \Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent
   */
  public const SOLD_ELSEWHERE = 'commerce_domains_marketplace.sold_elsewhere';

  /**
   * A marketplace sync failed.
   *
   * Dispatched when a queued push or sale check fails for good (after
   * Advanced Queue's retries), when a sync someone runs fails, and when a
   * marketplace refuses domains it had not refused before.
   *
   * @Event
   *
   * @see \Drupal\commerce_domains_marketplace\Event\SyncFailedEvent
   */
  public const SYNC_FAILED = 'commerce_domains_marketplace.sync_failed';

}

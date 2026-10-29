<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Event;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * A marketplace sync failed, as a whole or for some domains.
 *
 * @see \Drupal\commerce_domains_marketplace\Event\MarketplaceEvents::SYNC_FAILED
 */
final class SyncFailedEvent extends Event {

  public function __construct(
    // The marketplace.
    public readonly ProviderInterface $provider,
    // Error messages keyed by domain, or by '' for a failure of the whole
    // run. API clients keep credentials out of them.
    public readonly array $errors,
  ) {}

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Event;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * A marketplace shows a catalog domain sold, or a sale under way.
 *
 * By the time it is dispatched the domain is off sale on the site, and the
 * removal of its listings on the other marketplaces is scheduled; the
 * selling marketplace's listing is left alone.
 *
 * @see \Drupal\commerce_domains_marketplace\Event\MarketplaceEvents::SOLD_ELSEWHERE
 */
final class SoldElsewhereEvent extends Event {

  public function __construct(
    // The marketplace selling it.
    public readonly ProviderInterface $provider,
    // The domain, normalized.
    public readonly string $domain,
    // The domain's product; NULL when it just left the catalog.
    public readonly ?ProductInterface $product,
    // The marketplace's status for the sale, e.g. "SOLD" or "pending sale".
    public readonly string $status,
  ) {}

}

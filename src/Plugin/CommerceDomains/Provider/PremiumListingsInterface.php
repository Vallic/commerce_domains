<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * A marketplace that lists and prices some domains as its own premium names.
 *
 * Each check sets a listed domain's Atom premium flag from its listing, and
 * the product form locks the flag.
 */
interface PremiumListingsInterface extends MarketplaceInterface {

  /**
   * Whether a listing is one of the marketplace's premium names.
   */
  public function isPremium(array $current): bool;

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Event;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Asks whether a domain takes offers on the site.
 *
 * Starts from the domain's sale terms. Subscribers add the site's own rules,
 * such as no offers below a price.
 */
final class OfferAllowedEvent extends Event {

  // The event name.
  public const NAME = 'commerce_domains_offer.allowed';

  public function __construct(
    public readonly ProductVariationInterface $variation,
    protected bool $allowed,
  ) {}

  /**
   * Returns whether offers are allowed.
   */
  public function isAllowed(): bool {
    return $this->allowed;
  }

  /**
   * Allows or refuses offers.
   */
  public function setAllowed(bool $allowed): void {
    $this->allowed = $allowed;
  }

}

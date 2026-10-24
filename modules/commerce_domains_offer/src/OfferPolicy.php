<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer;

use Drupal\commerce\Context;
use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_domains_offer\Event\OfferAllowedEvent;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Resolver\ChainPriceResolverInterface;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Decides which domains take offers, and the least they take.
 */
class OfferPolicy {

  public function __construct(
    protected Availability $availability,
    protected EventDispatcherInterface $eventDispatcher,
    protected ChainPriceResolverInterface $chainPriceResolver,
    protected RounderInterface $rounder,
  ) {}

  /**
   * Returns whether a domain takes offers on the site.
   *
   * It must be for sale, its sale terms must accept offers, and no
   * subscriber to OfferAllowedEvent may refuse.
   */
  public function allows(ProductVariationInterface $variation): bool {
    $product = $variation->getProduct();
    if (!$product || !$this->availability->isForSale($product, $variation)) {
      return FALSE;
    }
    $event = new OfferAllowedEvent($variation, SaleTerms::fromVariation($variation)->offers);
    $this->eventDispatcher->dispatch($event, OfferAllowedEvent::NAME);
    return $event->isAllowed();
  }

  /**
   * Returns the price the buyer sees, which offers are made in.
   */
  public function price(ProductVariationInterface $variation, Context $context): ?Price {
    return $this->chainPriceResolver->resolve($variation, '1', $context);
  }

  /**
   * Returns the least the domain takes, in the currency of a price.
   *
   * The domain's own minimum offer, moved with the price into another
   * currency; otherwise the rule the marketplaces use.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The domain's variation.
   * @param \Drupal\commerce_price\Price $price
   *   The price the buyer sees.
   */
  public function minimum(ProductVariationInterface $variation, Price $price): Price {
    $number = (float) $price->getNumber();
    $own = SaleTerms::fromVariation($variation)->minimumOffer;
    $base = $variation->getPrice();
    if ($own && $own->getCurrencyCode() === $price->getCurrencyCode()) {
      $minimum = (float) $own->getNumber();
    }
    elseif ($own && $base && $own->getCurrencyCode() === $base->getCurrencyCode() && (float) $base->getNumber() > 0) {
      $minimum = (float) $own->getNumber() * $number / (float) $base->getNumber();
    }
    else {
      $minimum = SaleTerms::defaultMinimumOffer($number);
    }
    return $this->rounder->round(new Price((string) min($minimum, $number), $price->getCurrencyCode()));
  }

}

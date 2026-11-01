<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Entity;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * An offer a buyer made on a domain.
 *
 * Pending until the seller accepts, declines or counters it; a counter-offer
 * is the buyer's to accept or decline. Accepting creates an order at the
 * agreed amount, and placing that order marks the offer purchased.
 */
interface OfferInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  // Offers made through the site's offer form.
  public const SOURCE_SITE = 'site';

  /**
   * Returns the variation the offer is for.
   */
  public function getVariation(): ?ProductVariationInterface;

  /**
   * Returns the domain, as it was when the offer was made.
   */
  public function getDomain(): string;

  /**
   * Returns the store the offer was made in.
   */
  public function getStore(): ?StoreInterface;

  /**
   * Returns where the offer was made: "site", or a channel like "escrow".
   *
   * Offers made elsewhere are negotiated there; the site only follows them.
   */
  public function getSource(): string;

  /**
   * Returns the amount the buyer offered, if they named one.
   */
  public function getAmount(): ?Price;

  /**
   * Returns the seller's counter-offer, if any.
   */
  public function getCounterAmount(): ?Price;

  /**
   * Sets the seller's counter-offer.
   *
   * @return $this
   */
  public function setCounterAmount(Price $amount): static;

  /**
   * Returns the amount accepting settles on: the counter-offer, if any.
   */
  public function getAgreedAmount(): ?Price;

  /**
   * Returns the buyer's email address.
   */
  public function getEmail(): string;

  /**
   * Returns the buyer's name.
   */
  public function getBuyerName(): string;

  /**
   * Returns the offer's state.
   */
  public function getState(): StateItemInterface;

  /**
   * Returns the order accepting created, if any.
   */
  public function getOrder(): ?OrderInterface;

  /**
   * Sets the order accepting created.
   *
   * @return $this
   */
  public function setOrder(OrderInterface $order): static;

  /**
   * Returns the provider the buyer pays through, for a payment link.
   */
  public function getPaymentLinkProvider(): ?ProviderInterface;

  /**
   * Returns the provider's ID for the payment link, or '' for none.
   */
  public function getPaymentLinkId(): string;

  /**
   * Returns where the buyer pays, or '' without a payment link.
   */
  public function getPaymentLinkUrl(): string;

  /**
   * Records the payment link made for the accepted offer.
   *
   * @return $this
   */
  public function setPaymentLink(ProviderInterface $provider, PaymentLink $link, ?int $lease_months = NULL): static;

  /**
   * Returns the monthly payments of a lease to own paid by link, or NULL.
   */
  public function getPaymentLinkLeaseMonths(): ?int;

  /**
   * Returns the secret that gives the buyer's links access.
   */
  public function getToken(): string;

}

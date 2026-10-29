<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Entity;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;

/**
 * Handing one sold domain over to its buyer.
 *
 * One per order item. It waits for the seller, who unlocks the domain and
 * has the auth code emailed to the buyer, or pushes it to the buyer's account
 * at the same registrar, and marks it transferred once the domain has left
 * the account. An auth code is never kept here.
 */
interface HandoverInterface extends ContentEntityInterface, EntityChangedInterface {

  // The states a handover is finished in.
  public const CLOSED = ['transferred', 'canceled'];

  /**
   * Returns the order.
   */
  public function getOrder(): ?OrderInterface;

  /**
   * Returns the order ID.
   */
  public function getOrderId(): ?int;

  /**
   * Returns the order item of the domain.
   */
  public function getOrderItem(): ?OrderItemInterface;

  /**
   * Returns the domain's product.
   */
  public function getProduct(): ?ProductInterface;

  /**
   * Returns the domain, as it was sold.
   */
  public function getDomain(): string;

  /**
   * Returns the seller's registrar for the domain.
   *
   * Where the catalog records the domain while the handover is open, and
   * where it was at the last step once the handover is closed.
   */
  public function getRegistrar(): ?ProviderInterface;

  /**
   * Returns where the buyer asked for the domain to go, if they were asked.
   */
  public function getTransfer(): ?Transfer;

  /**
   * Returns the handover's state.
   */
  public function getState(): StateItemInterface;

  /**
   * Whether the handover still waits for the seller or the buyer.
   */
  public function isOpen(): bool;

  /**
   * Returns when the domain was transferred, as a Unix time.
   */
  public function getTransferredTime(): ?int;

  /**
   * Sets when the domain was transferred.
   *
   * @return $this
   */
  public function setTransferredTime(int $time): static;

  /**
   * Returns when the handover started, as a Unix time.
   */
  public function getCreatedTime(): int;

}

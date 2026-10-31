<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Entity;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A lease to own: a domain paid for monthly, after a down payment.
 *
 * The lease's order holds the domain at its full price. The down payment
 * and each monthly payment are payments on that order, so the order counts
 * as paid, and the domain's handover starts, with the last one.
 *
 * A lease with a saved card is charged each month. One without, paid by
 * bank transfer, Wise or another gateway the buyer pays through, is a
 * manual lease: the buyer is asked for each payment, and it counts once it
 * is on the order, recorded by the owner or reported by the gateway.
 */
interface LeaseInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  // Payments are due as planned.
  public const ACTIVE = 'active';

  // A payment failed; it is retried every few days.
  public const OVERDUE = 'overdue';

  // Payments failed past the retries: the owner decides what happens.
  public const DEFAULTED = 'defaulted';

  // Paid in full.
  public const COMPLETED = 'completed';

  // Stopped by the owner; nothing more is charged.
  public const CANCELED = 'canceled';

  /**
   * Returns the payment gateway the lease is paid through.
   */
  public function getPaymentGateway(): ?PaymentGatewayInterface;

  /**
   * Returns whether the buyer pays each payment, rather than a saved card.
   */
  public function isManual(): bool;

  /**
   * Returns when the payment due was asked for, on a manual lease.
   *
   * @return int|null
   *   A timestamp, or NULL when it has not been yet.
   */
  public function getRequested(): ?int;

  /**
   * Returns the order the domain was leased with.
   */
  public function getOrder(): ?OrderInterface;

  /**
   * Returns the domain, as the buyer saw it.
   */
  public function getDomain(): string;

  /**
   * Returns the saved card the monthly payments are charged to.
   */
  public function getPaymentMethod(): ?PaymentMethodInterface;

  /**
   * Returns the number of monthly payments after the down payment.
   */
  public function getMonths(): int;

  /**
   * Returns what the domain costs in all.
   */
  public function getTotal(): Price;

  /**
   * Returns what was paid at checkout.
   */
  public function getDownPayment(): Price;

  /**
   * Returns a monthly payment; the last one makes up the rest.
   */
  public function getInstallment(): Price;

  /**
   * Returns what was paid so far, the down payment included.
   */
  public function getPaid(): Price;

  /**
   * Returns what is left to pay.
   */
  public function getRemaining(): Price;

  /**
   * Returns how many monthly payments were made.
   */
  public function getPaymentsMade(): int;

  /**
   * Returns the amount of the next monthly payment.
   */
  public function getNextAmount(): Price;

  /**
   * Returns when the next payment is due, or retried, as a Unix time.
   */
  public function getNextDue(): ?int;

  /**
   * Returns how many attempts at the current payment failed.
   */
  public function getFailures(): int;

  /**
   * Returns the state: ACTIVE, OVERDUE, DEFAULTED, COMPLETED or CANCELED.
   */
  public function getState(): string;

  /**
   * Whether payments are still charged: ACTIVE or OVERDUE.
   */
  public function isOpen(): bool;

  /**
   * Returns the states, labeled.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by state.
   */
  public static function stateLabels(): array;

}

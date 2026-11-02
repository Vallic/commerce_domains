<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Entity;

use Drupal\commerce_price\Price;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A payment that kept a domain registered: its renewal, or a transfer's year.
 */
interface DomainRenewalInterface extends ContentEntityInterface {

  // A renewal at the registrar.
  public const RENEWAL = 'renewal';

  // A transfer to another registrar, which pays for a year.
  public const TRANSFER = 'transfer';

  // Bringing back an expired domain.
  public const RESTORE = 'restore';

  /**
   * Returns the domain's name, kept if the product is deleted.
   */
  public function getDomain(): string;

  /**
   * Returns when it was paid, as a Unix time.
   */
  public function getPaid(): int;

  /**
   * Returns how many years it paid for.
   */
  public function getYears(): int;

  /**
   * Returns what it cost, in the currency charged, when known.
   */
  public function getAmount(): ?Price;

  /**
   * Returns the type: RENEWAL, TRANSFER or RESTORE.
   */
  public function getType(): string;

  /**
   * Returns the ID identifying where it came from, so imports don't repeat it.
   */
  public function getExternalId(): string;

  /**
   * Whether it was inferred, rather than read from what the registrar charged.
   */
  public function isEstimated(): bool;

}

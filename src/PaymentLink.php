<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * A link a provider made for a buyer to pay for a domain there.
 *
 * The provider takes the payment and hands the domain over; the site reads
 * the link's state back to know when it sold.
 */
final class PaymentLink {

  // Waiting for the buyer: to confirm, or to pay.
  public const PENDING = 'pending';

  // Paid: the domain is the buyer's, or being handed over.
  public const PAID = 'paid';

  // Canceled or expired before it was paid.
  public const ENDED = 'ended';

  public function __construct(
    // The provider's ID for the link.
    public readonly string $id,
    // Where the buyer pays.
    public readonly string $url,
    // PENDING, PAID or ENDED.
    public readonly string $state,
    // The provider's own status, for people reading it.
    public readonly ?string $status = NULL,
  ) {}

}

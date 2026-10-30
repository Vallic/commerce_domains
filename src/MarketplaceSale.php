<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * A sale a marketplace reports, with what it brought.
 *
 * NULL values are ones the marketplace doesn't report, or doesn't know yet.
 */
final class MarketplaceSale {

  public function __construct(
    // The domain in lowercase ASCII (IDNA) form.
    public readonly string $name,
    // When it sold, as a Unix time.
    public readonly ?int $sold,
    // What the buyer pays, in $currency.
    public readonly ?float $price = NULL,
    // What the seller gets after commission; NULL until it is worked out.
    public readonly ?float $received = NULL,
    // The currency of both amounts.
    public readonly string $currency = 'USD',
    // For a lease to own, the number of monthly payments.
    public readonly ?int $leaseMonths = NULL,
    // The marketplace's status for the sale or its transfer.
    public readonly ?string $status = NULL,
  ) {}

}

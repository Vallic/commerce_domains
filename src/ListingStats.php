<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * How much interest a listing drew, as the marketplace counts it.
 *
 * NULL counts are ones the marketplace doesn't report.
 */
final class ListingStats {

  public function __construct(
    // Visits to the listing or its landing page.
    public readonly ?int $views,
    // Inquiries and other leads.
    public readonly ?int $leads = NULL,
    // Offers made.
    public readonly ?int $offers = NULL,
    // Times buyers saved it, e.g. Atom's shortlists.
    public readonly ?int $saves = NULL,
    // The days counted back from today; NULL for all time.
    public readonly ?int $days = NULL,
  ) {}

}

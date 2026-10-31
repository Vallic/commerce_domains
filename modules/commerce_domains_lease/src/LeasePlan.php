<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease;

use Drupal\commerce_price\Price;

/**
 * What a lease costs: a down payment, then equal monthly payments.
 *
 * The last monthly payment makes up what rounding the others left over, so
 * the payments add up to the total exactly.
 */
final class LeasePlan {

  public function __construct(
    // The number of monthly payments after the down payment.
    public readonly int $months,
    // What the domain costs in all.
    public readonly Price $total,
    // What is paid at checkout.
    public readonly Price $downPayment,
    // A monthly payment.
    public readonly Price $installment,
  ) {}

  /**
   * Returns the last monthly payment, which makes up the rest.
   */
  public function lastInstallment(): Price {
    return $this->total->subtract($this->downPayment)->subtract($this->installment->multiply((string) ($this->months - 1)));
  }

}

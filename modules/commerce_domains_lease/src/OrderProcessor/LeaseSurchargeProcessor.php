<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\OrderProcessor;

use Drupal\commerce_domains_lease\LeaseCalculator;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adds what a lease costs over paying in full, as a fee on the order.
 *
 * Once the buyer has chosen a term the order can be leased over, and a
 * surcharge is set for it. It runs after promotions and tax, so the
 * percentage is of what paying in full costs, tax included. The order's
 * total, its payments and its invoice then all include it.
 */
final class LeaseSurchargeProcessor implements OrderProcessorInterface {

  public function __construct(
    protected LeaseCalculator $calculator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    $months = LeaseCalculator::chosenMonths($order);
    if ($months === NULL) {
      return;
    }
    // The refresh removed the last surcharge; the total doesn't know yet.
    $order->recalculateTotalPrice();
    $total = $order->getTotalPrice();
    if (!$total || !in_array($months, $this->calculator->terms($order), TRUE)) {
      return;
    }
    $surcharge = $this->calculator->surcharge($total, $months);
    if (!$surcharge->isPositive()) {
      return;
    }
    $order->addAdjustment(new Adjustment([
      'type' => 'fee',
      'label' => (string) new TranslatableMarkup('Lease to own, @count months', ['@count' => $months]),
      'amount' => $surcharge,
      'percentage' => (string) ($this->calculator->surchargePercent($months) / 100),
      'source_id' => LeaseCalculator::ADJUSTMENT_SOURCE,
    ]));
  }

}

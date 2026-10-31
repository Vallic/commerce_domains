<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease;

use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Works out whether an order can be leased, for how long, and the payments.
 *
 * An order of one domain that sells at its price can be leased when its sale
 * terms allow a lease (see SaleTerms::leaseMonthsAt()), the buyer has an
 * account, and a payment gateway that can charge a saved card is enabled:
 * the monthly payments are charged to the card the down payment was paid
 * with, which only works for cards saved to an account.
 */
class LeaseCalculator {

  // The order data key holding the buyer's choice: ['months' => int].
  public const DATA_KEY = 'commerce_domains_lease';

  // The terms offered, in months, up to the domain's longest lease.
  public const TERMS = [6, 12, 24, 36, 48, 60];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RounderInterface $rounder,
  ) {}

  /**
   * Returns the order's domain item, when the order is one domain.
   */
  public function domainItem(OrderInterface $order): ?OrderItemInterface {
    $items = $order->getItems();
    if (count($items) !== 1) {
      return NULL;
    }
    $item = reset($items);
    $variation = $item->getPurchasedEntity();
    $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
    return $product && $product->hasField('domain') ? $item : NULL;
  }

  /**
   * Returns the terms the order can be leased over, in months.
   *
   * @return int[]
   *   The terms, shortest first; empty when the order can't be leased.
   */
  public function terms(OrderInterface $order): array {
    $item = $this->domainItem($order);
    $total = $order->getTotalPrice();
    $variation = $item?->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface || !$total || !$total->isPositive() || !$variation->hasField('sale_lease_months')) {
      return [];
    }
    $terms = SaleTerms::fromVariation($variation);
    $longest = $terms->buyNow ? $terms->leaseMonthsAt((float) $total->getNumber()) : 0;
    if ($longest < 2) {
      return [];
    }
    $offered = array_filter(self::TERMS, fn(int $months) => $months <= $longest);
    $offered[] = $longest;
    $offered = array_values(array_unique($offered));
    sort($offered);
    return $offered;
  }

  /**
   * Returns the share of the price paid at checkout, in percent.
   */
  public function downPaymentPercent(OrderInterface $order): int {
    $variation = $this->domainItem($order)?->getPurchasedEntity();
    $percent = $variation instanceof ProductVariationInterface ? SaleTerms::fromVariation($variation)->leaseDownPayment : NULL;
    $percent ??= (int) $this->configFactory->get('commerce_domains_lease.settings')->get('default_down_payment');
    // At least something at checkout, and something left for the months.
    return max(1, min(90, $percent));
  }

  /**
   * Returns what a lease of the order over some months costs.
   */
  public function plan(OrderInterface $order, int $months): ?LeasePlan {
    $total = $order->getTotalPrice();
    if (!$total || $months < 2) {
      return NULL;
    }
    return self::planFor($total, $months, $this->downPaymentPercent($order), $this->rounder);
  }

  /**
   * Returns what a lease of an amount costs.
   */
  public static function planFor(Price $total, int $months, int $percent, RounderInterface $rounder): LeasePlan {
    $down = $rounder->round($total->multiply((string) ($percent / 100)));
    $installment = $rounder->round($total->subtract($down)->divide((string) $months), PHP_ROUND_HALF_DOWN);
    return new LeasePlan($months, $total, $down, $installment);
  }

  /**
   * Returns the buyer's choice of term, or NULL to pay in full.
   */
  public static function chosenMonths(OrderInterface $order): ?int {
    $months = $order->getData(self::DATA_KEY)['months'] ?? NULL;
    return is_int($months) && $months >= 2 ? $months : NULL;
  }

  /**
   * Returns the IDs of the enabled payment gateways leases can be paid with.
   *
   * @return string[]
   *   Payment gateway IDs.
   */
  public function gateways(): array {
    $plugins = (array) $this->configFactory->get('commerce_domains_lease.settings')->get('gateway_plugins');
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[] $gateways */
    $gateways = $this->entityTypeManager->getStorage('commerce_payment_gateway')->loadByProperties(['status' => TRUE]);
    return array_keys(array_filter($gateways, fn($gateway) => in_array($gateway->getPluginId(), $plugins, TRUE)));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Adds up what the portfolio cost: purchases and renewals, by year.
 *
 * Every domain counts, held or sold: its purchase in the year it was bought,
 * and its renewals from the purchase up to the sale (or today), those recorded
 * in the year they were paid at what they cost, and an estimate for the years
 * they don't cover (see Costs). Sales count what was received, after the
 * marketplace's commission. Amounts stay in their currency; net is worked out
 * for each.
 */
class Expenses {

  // Products loaded at once.
  protected const CHUNK_SIZE = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected Costs $costs,
    protected RenewalHistory $history,
  ) {}

  /**
   * Adds up the portfolio's spending and income by year.
   *
   * @param int $now
   *   Today, as a Unix time: renewals of domains still held count up to it.
   *
   * @return array{years: array<int, array{bought: int, purchases: \Drupal\commerce_price\Price[], renewals: int, estimated: int, renewal_costs: \Drupal\commerce_price\Price[], sold: int, received: \Drupal\commerce_price\Price[]}>, unpriced: int, undated: int}
   *   Totals keyed by year, latest first, amounts keyed by currency, with how
   *   many of the renewal years are estimated; and how many domains have no
   *   purchase price, or a price but no purchase date (whose unrecorded
   *   renewals can't be counted).
   */
  public function byYear(int $now): array {
    $years = [];
    $unpriced = 0;
    $undated = 0;
    $row = [
      'bought' => 0,
      'purchases' => [],
      'renewals' => 0,
      'estimated' => 0,
      'renewal_costs' => [],
      'sold' => 0,
      'received' => [],
    ];
    $this->each(function (ProductInterface $product) use (&$years, &$unpriced, &$undated, $row, $now): void {
      $purchase = Costs::price($product, 'domain_purchase_price');
      $purchased = Costs::time($product, 'domain_purchase_date');
      $sold = Costs::time($product, 'domain_sold');
      if (!$purchase) {
        $unpriced++;
      }
      elseif ($purchased === NULL) {
        $undated++;
      }
      if ($purchase && $purchased !== NULL) {
        $year = $this->year($purchased);
        $years[$year] ??= $row;
        $years[$year]['bought']++;
        self::addTo($years[$year]['purchases'], $purchase);
      }
      foreach ($this->costs->renewalPayments($product, min($sold ?? $now, $now)) as $payment) {
        $year = $this->year($payment['time']);
        $years[$year] ??= $row;
        $years[$year]['renewals'] += $payment['years'];
        $years[$year]['estimated'] += $payment['estimated'] ? $payment['years'] : 0;
        if ($payment['amount']) {
          self::addTo($years[$year]['renewal_costs'], $payment['amount']);
        }
      }
      if ($sold !== NULL) {
        $profit = $this->costs->profit($product);
        if ($profit->salePrice) {
          $year = $this->year($sold);
          $years[$year] ??= $row;
          $years[$year]['sold']++;
          self::addTo($years[$year]['received'], $profit->received());
        }
      }
    });
    krsort($years);
    return ['years' => $years, 'unpriced' => $unpriced, 'undated' => $undated];
  }

  /**
   * Adds up the renewals coming due on the domains kept.
   *
   * @param int $now
   *   Today, as a Unix time.
   * @param int $days
   *   How far ahead.
   *
   * @return array{count: int, costs: \Drupal\commerce_price\Price[], unknown: int}
   *   How many domains renew, what that costs by currency, and how many have
   *   no renewal cost to count.
   */
  public function upcoming(int $now, int $days = 365): array {
    $total = ['count' => 0, 'costs' => [], 'unknown' => 0];
    $this->each(function (ProductInterface $product) use (&$total, $now, $days): void {
      $expires = Costs::time($product, 'domain_expires');
      $leaving = $product->hasField('domain_renewal') && $product->get('domain_renewal')->value === DomainCosts::EXPIRE;
      if ($leaving || Costs::time($product, 'domain_sold') !== NULL || $expires === NULL || $expires <= $now || $expires > $now + $days * 86400) {
        return;
      }
      $total['count']++;
      $cost = $this->costs->renewalCost($product);
      if ($cost) {
        self::addTo($total['costs'], $cost);
      }
      else {
        $total['unknown']++;
      }
    });
    ksort($total['costs']);
    return $total;
  }

  /**
   * Returns what a domain held has cost so far.
   *
   * @return array{purchase: \Drupal\commerce_price\Price|null, renewals: int, renewals_paid: \Drupal\commerce_price\Price|null, total: \Drupal\commerce_price\Price|null, renewal: \Drupal\commerce_price\Price|null, default: bool}
   *   The purchase price, the renewals paid and what they cost, the two
   *   together (NULL without a purchase price, or without an exchange rate
   *   between them), the yearly renewal cost, and whether it is the
   *   extension's default.
   */
  public function invested(ProductInterface $product, int $now): array {
    $purchase = Costs::price($product, 'domain_purchase_price');
    $renewal = $this->costs->renewalCost($product);
    $payments = $this->costs->renewalPayments($product, $now);
    $renewals = array_sum(array_column($payments, 'years'));
    $paid = $this->costs->renewalsTotal($payments, $purchase?->getCurrencyCode());
    $total = $purchase;
    if ($purchase && $paid) {
      $converted = $this->costs->convert($paid, $purchase->getCurrencyCode());
      $total = $converted ? $purchase->add($converted) : NULL;
    }
    elseif ($purchase && array_filter(array_column($payments, 'amount'))) {
      $total = NULL;
    }
    return [
      'purchase' => $purchase,
      'renewals' => $renewals,
      'renewals_paid' => $paid,
      'total' => $total,
      'renewal' => $renewal,
      'default' => $this->costs->renewsAtDefault($product),
    ];
  }

  /**
   * Returns the IDs of the domains held, a page of 50 with a pager.
   *
   * @return int[]
   *   Product IDs, by domain.
   */
  public function heldIds(): array {
    $query = $this->entityTypeManager->getStorage('commerce_product')->getQuery()
      ->accessCheck(FALSE)
      ->exists('domain')
      ->notExists('domain_sold')
      ->sort('domain')
      ->pager(50);
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Calls a function for every domain, a chunk of products at a time.
   */
  protected function each(callable $callback): void {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->exists('domain')->execute();
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $product) {
        if ($product instanceof ProductInterface && $product->hasField('domain_purchase_price')) {
          $callback($product);
        }
      }
      $storage->resetCache($chunk);
      $this->history->resetCache();
    }
  }

  /**
   * Returns the year of a time, in the site's time zone.
   */
  protected function year(int $time): int {
    return (int) $this->dateFormatter->format($time, 'custom', 'Y');
  }

  /**
   * Adds an amount to the total of its currency.
   *
   * @param \Drupal\commerce_price\Price[] $totals
   *   Totals keyed by currency.
   * @param \Drupal\commerce_price\Price $price
   *   The amount.
   */
  public static function addTo(array &$totals, Price $price): void {
    $currency = $price->getCurrencyCode();
    $totals[$currency] = isset($totals[$currency]) ? $totals[$currency]->add($price) : $price;
    ksort($totals);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Lists sold domains with their profit, for the dashboard and the report.
 */
class SalesReport {

  use StringTranslationTrait;

  // Products loaded at once for the totals.
  protected const CHUNK_SIZE = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected Costs $costs,
  ) {}

  /**
   * Returns the IDs of sold domains, latest sale first.
   *
   * @param int|null $limit
   *   How many, or NULL for a page of 50 with a pager.
   *
   * @return int[]
   *   Product IDs.
   */
  public function soldIds(?int $limit = NULL): array {
    $query = $this->entityTypeManager->getStorage('commerce_product')->getQuery()
      ->accessCheck(FALSE)
      ->exists('domain_sold')
      ->sort('domain_sold', 'DESC')
      ->sort('product_id', 'DESC');
    if ($limit) {
      $query->range(0, $limit);
    }
    else {
      $query->pager(50);
    }
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Builds the table of some sold domains.
   *
   * @param int[] $ids
   *   Product IDs.
   */
  public function table(array $ids): array {
    $rows = [];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($this->entityTypeManager->getStorage('commerce_product')->loadMultiple($ids) as $id => $product) {
      $profit = $this->costs->profit($product);
      $paid = ['@paid' => $this->money($profit->renewalsPaid)];
      $problem = ['data' => ['#markup' => $profit->problem], 'class' => ['color-warning']];
      $rows[$id] = [
        ['data' => $product->toLink()->toRenderable()],
        $this->dateFormatter->format((int) $product->get('domain_sold')->value, 'custom', 'Y-m-d'),
        ['data' => $this->channel($product)],
        $this->soldFor($product, $profit->salePrice),
        $this->money($profit->purchasePrice),
        $profit->renewalsPaid
          ? $this->formatPlural($profit->renewals, '@paid (1 renewal)', '@paid (@count renewals)', $paid)
          : '',
        $profit->profit ? $this->money($profit->profit) : $problem,
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Domain'),
        $this->t('Sold'),
        $this->t('Sold on'),
        $this->t('Sold for'),
        $this->t('Purchase price'),
        $this->t('Renewals paid'),
        $this->t('Profit'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No domain has sold yet.'),
    ];
  }

  /**
   * Adds up the sales of every sold domain, by year and currency.
   *
   * @return array<int, array{sales: int, leases: int, unknown: int, revenue: \Drupal\commerce_price\Price[], profit: \Drupal\commerce_price\Price[]}>
   *   Totals keyed by year, latest first. Revenue and profit are keyed by
   *   currency; leases counts the leases to own among the sales, unknown
   *   the sales without a profit.
   */
  public function totals(): array {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(FALSE)->exists('domain_sold')->execute();
    $totals = [];
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($storage->loadMultiple($chunk) as $product) {
        $year = (int) $this->dateFormatter->format((int) $product->get('domain_sold')->value, 'custom', 'Y');
        $totals[$year] ??= ['sales' => 0, 'leases' => 0, 'unknown' => 0, 'revenue' => [], 'profit' => []];
        $totals[$year]['sales']++;
        if (self::leaseMonths($product)) {
          $totals[$year]['leases']++;
        }
        $profit = $this->costs->profit($product);
        if ($profit->salePrice) {
          self::addTo($totals[$year]['revenue'], $profit->salePrice);
        }
        if ($profit->profit) {
          self::addTo($totals[$year]['profit'], $profit->profit);
        }
        else {
          $totals[$year]['unknown']++;
        }
      }
      $storage->resetCache($chunk);
    }
    krsort($totals);
    return $totals;
  }

  /**
   * Builds the table of totals by year.
   */
  public function totalsTable(): array {
    $amounts = fn(array $prices) => ['data' => ['#markup' => implode('<br>', array_map(fn(Price $price) => $this->money($price), $prices))]];
    $rows = [];
    foreach ($this->totals() as $year => $total) {
      $rows[] = [
        (string) $year,
        $total['sales'],
        $total['leases'] ?: '',
        $amounts($total['revenue']),
        $amounts($total['profit']),
        $total['unknown'] ?: '',
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Year'),
        $this->t('Domains sold'),
        $this->t('Leases to own'),
        $this->t('Sold for'),
        $this->t('Profit'),
        $this->t('Without a profit'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No domain has sold yet.'),
    ];
  }

  /**
   * Says where a domain sold: in an order here, or on a marketplace.
   */
  public function channel(ProductInterface $product): array {
    $order_id = (int) $product->get('domain_sale_order')->value;
    if ($order_id && $this->entityTypeManager->hasDefinition('commerce_order')) {
      $url = Url::fromRoute('entity.commerce_order.canonical', ['commerce_order' => $order_id]);
      return Link::fromTextAndUrl($this->t('This site, order @id', ['@id' => $order_id]), $url)->toRenderable();
    }
    $provider = $product->get('domain_sold_via')->entity;
    return ['#plain_text' => $provider ? $provider->label() : $this->t('Elsewhere')];
  }

  /**
   * Says what a domain sold for, with the term of a lease to own.
   */
  public function soldFor(ProductInterface $product, ?Price $price): string {
    $months = self::leaseMonths($product);
    if (!$months) {
      return $this->money($price);
    }
    $sold = new \DateTimeImmutable('@' . (int) $product->get('domain_sold')->value);
    $args = [
      '@price' => $this->money($price),
      '@ends' => $this->dateFormatter->format($sold->modify(sprintf('+%d months', $months))->getTimestamp(), 'custom', 'Y-m'),
    ];
    return $price
      ? (string) $this->formatPlural($months, '@price, lease to own over 1 month to @ends', '@price, lease to own over @count months to @ends', $args)
      : (string) $this->formatPlural($months, 'Lease to own over 1 month to @ends', 'Lease to own over @count months to @ends', $args);
  }

  /**
   * Returns the monthly payments of a domain's lease to own, or 0.
   */
  public static function leaseMonths(ProductInterface $product): int {
    return $product->hasField('domain_lease_months') ? (int) $product->get('domain_lease_months')->value : 0;
  }

  /**
   * Formats an amount.
   */
  public function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

  /**
   * Adds an amount to the total of its currency.
   *
   * @param \Drupal\commerce_price\Price[] $totals
   *   Totals keyed by currency.
   * @param \Drupal\commerce_price\Price $price
   *   The amount.
   */
  protected static function addTo(array &$totals, Price $price): void {
    $currency = $price->getCurrencyCode();
    $totals[$currency] = isset($totals[$currency]) ? $totals[$currency]->add($price) : $price;
    ksort($totals);
  }

}

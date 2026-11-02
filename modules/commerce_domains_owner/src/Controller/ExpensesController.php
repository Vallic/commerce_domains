<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Controller;

use Drupal\commerce_domains_owner\Expenses;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_domains_owner\SalesReport;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * What the portfolio cost: by year, the renewals ahead, and each domain held.
 */
final class ExpensesController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    protected Renewals $renewals,
    protected Expenses $expenses,
    protected SalesReport $salesReport,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {}

  /**
   * Builds the page.
   */
  public function build(): array {
    if (!$this->renewals->hasCosts()) {
      return ['#markup' => '<p>' . $this->t('Turn on the <em>Domain costs and sale</em> trait for your domain product type to record what domains cost.') . '</p>'];
    }
    $now = $this->time->getRequestTime();
    $by_year = $this->expenses->byYear($now);
    $upcoming = $this->expenses->upcoming($now);
    $settings = Url::fromRoute('commerce_domains_owner.settings')->toString();

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Every domain counts, held or sold: its purchase in the year it was bought, and its renewals. <a href=":renewals">Renewals paid</a> count at what they cost, in the year they were paid; the years they do not cover are estimated from the renewal cost, the latest renewal paid, or the extension\'s default (<a href=":settings">Domain portfolio settings</a>). Received is what sales brought after commission. Net is received less purchases and renewals, in each currency.', [
        ':settings' => $settings,
        ':renewals' => Url::fromRoute('entity.commerce_domains_renewal.collection')->toString(),
      ]) . '</p>',
    ];
    $notes = [];
    if ($by_year['unpriced']) {
      $notes[] = $this->formatPlural($by_year['unpriced'], '1 domain has no purchase price, so it counts nothing.', '@count domains have no purchase price, so they count nothing.');
    }
    if ($by_year['undated']) {
      $notes[] = $this->formatPlural($by_year['undated'], '1 domain has a purchase price but no purchase date, so it is left out.', '@count domains have a purchase price but no purchase date, so they are left out.');
    }
    if ($notes) {
      $build['notes'] = ['#markup' => '<p class="color-warning">' . implode(' ', array_map('strval', $notes)) . '</p>'];
    }

    $rows = [];
    foreach ($by_year['years'] as $year => $total) {
      $rows[] = [
        (string) $year,
        $total['bought'] ?: '',
        $this->amounts($total['purchases']),
        $total['estimated'] ? $this->t('@count (@estimated estimated)', [
          '@count' => $total['renewals'],
          '@estimated' => $total['estimated'],
        ]) : ($total['renewals'] ?: ''),
        $this->amounts($total['renewal_costs']),
        $total['sold'] ?: '',
        $this->amounts($total['received']),
        $this->amounts(self::net($total)),
      ];
    }
    $build['years'] = [
      '#type' => 'table',
      '#caption' => $this->t('By year'),
      '#header' => [
        $this->t('Year'),
        $this->t('Bought'),
        $this->t('Purchases'),
        $this->t('Renewals'),
        $this->t('Renewals paid'),
        $this->t('Sold'),
        $this->t('Received'),
        $this->t('Net'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No domain has a purchase price and date yet.'),
    ];

    $ahead = $upcoming['count']
      ? $this->formatPlural($upcoming['count'], '1 domain you keep renews in the next 12 months, for @costs.', '@count domains you keep renew in the next 12 months, for @costs.', [
        '@costs' => implode(' + ', array_map(fn(Price $price) => $this->salesReport->money($price), $upcoming['costs'])) ?: $this->t('an unknown amount'),
      ])
      : $this->t('No domain you keep renews in the next 12 months.');
    $unknown = $upcoming['unknown'] ? ' ' . $this->formatPlural($upcoming['unknown'], '1 of them has no renewal cost.', '@count of them have no renewal cost.') : '';
    $build['upcoming'] = ['#markup' => '<h3>' . $this->t('Next 12 months') . '</h3><p>' . $ahead . $unknown . '</p>'];

    $held = [];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($this->entityTypeManager->getStorage('commerce_product')->loadMultiple($this->expenses->heldIds()) as $id => $product) {
      $invested = $this->expenses->invested($product, $now);
      $purchased = $product->get('domain_purchase_date')->value;
      $expires = $product->get('domain_expires')->value;
      $costs = Url::fromRoute('commerce_domains.product_tab.domain_costs', ['commerce_product' => $id]);
      $link = $costs->access()
        ? ['#type' => 'link', '#title' => $product->label(), '#url' => $costs]
        : ['#plain_text' => $product->label()];
      $no_price = ['data' => $this->t('No purchase price'), 'class' => ['color-warning']];
      $paid = $invested['renewals_paid'] ? $this->salesReport->money($invested['renewals_paid']) : NULL;
      $held[] = [
        ['data' => $link],
        $purchased ? $this->dateFormatter->format((int) $purchased, 'custom', 'Y-m-d') : '',
        $invested['purchase'] ? $this->salesReport->money($invested['purchase']) : $no_price,
        $paid ? $this->formatPlural($invested['renewals'], '@paid (1 renewal)', '@paid (@count renewals)', ['@paid' => $paid]) : '',
        $invested['total'] ? $this->salesReport->money($invested['total']) : '',
        $invested['renewal'] ? $this->salesReport->money($invested['renewal']) . ($invested['default'] ? ' ' . $this->t('(default)') : '') : '',
        $expires ? $this->dateFormatter->format((int) $expires, 'custom', 'Y-m-d') : '',
      ];
    }
    $build['held'] = [
      '#type' => 'table',
      '#caption' => $this->t('Domains held'),
      '#header' => [
        $this->t('Domain'),
        $this->t('Bought'),
        $this->t('Purchase price'),
        $this->t('Renewals paid'),
        $this->t('Invested'),
        $this->t('Renewal a year'),
        $this->t('Expires'),
      ],
      '#rows' => $held,
      '#empty' => $this->t('No domain is held.'),
    ];
    $build['pager'] = ['#type' => 'pager'];
    // Exchange rates and today's date change without telling the cache.
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Works out a year's net in each currency: received less what was spent.
   *
   * @return \Drupal\commerce_price\Price[]
   *   Keyed by currency.
   */
  public static function net(array $total): array {
    $net = $total['received'];
    foreach (array_merge(array_values($total['purchases']), array_values($total['renewal_costs'])) as $cost) {
      Expenses::addTo($net, $cost->multiply('-1'));
    }
    return $net;
  }

  /**
   * Formats amounts in several currencies, one a line.
   *
   * @param \Drupal\commerce_price\Price[] $prices
   *   The amounts.
   */
  protected function amounts(array $prices): array|string {
    return $prices ? ['data' => ['#markup' => implode('<br>', array_map(fn(Price $price) => $this->salesReport->money($price), $prices))]] : '';
  }

}

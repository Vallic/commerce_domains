<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Controller;

use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_domains_owner\SalesReport;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Lists the sold domains with what each earned, and the totals by year.
 */
final class ProfitController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    protected Renewals $renewals,
    protected SalesReport $salesReport,
  ) {}

  /**
   * Builds the report.
   */
  public function build(): array {
    if (!$this->renewals->hasCosts()) {
      return ['#markup' => '<p>' . $this->t('Turn on the <em>Domain costs and sale</em> trait for your domain product type to record sales and their profit.') . '</p>'];
    }
    return [
      'formula' => [
        '#markup' => '<p>' . $this->t('Profit is what a domain sold for, minus what it cost you, minus the renewals you paid while you held it.') . '</p><p>'
        . $this->t('Renewals paid is the yearly renewal cost times the renewals between the purchase and the sale: one on each anniversary of the expiry date, or of the purchase date for a domain without one. Without a purchase date no renewals count, and without a renewal cost they cost nothing. Costs in another currency than the sale are converted at the current exchange rates of Commerce Exchanger, when it is installed.') . '</p><p>'
        . $this->t('Sales on this site fill in the price from the order. For a sale elsewhere, enter what you received after commission on the domain.') . '</p>',
      ],
      'totals' => $this->salesReport->totalsTable(),
      'sales' => $this->salesReport->table($this->salesReport->soldIds()),
      'pager' => ['#type' => 'pager'],
      // Exchange rates change without telling the cache.
      '#cache' => ['max-age' => 0],
    ];
  }

}

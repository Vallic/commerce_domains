<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\MarketplaceSale;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\PremiumListingsInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\SalesReportInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The test marketplace, with a sales report and premium names, like Atom.
 *
 * On top of TestMarketplace's State keys:
 * - sales: [sold, received, lease months] keyed by domain.
 * A listing with 'premium' => TRUE is one of its premium names.
 */
#[CommerceDomainsProvider(
  id: 'test_atom',
  label: new TranslatableMarkup('Test Atom'),
)]
final class TestAtom extends TestMarketplace implements SalesReportInterface, PremiumListingsInterface {

  /**
   * {@inheritdoc}
   */
  public function fetchSales(?array $names = NULL): array {
    $this->failIfAsked();
    $this->record('sales', $names);
    $sales = [];
    foreach ($this->state->get(static::STATE_PREFIX . 'sales', []) as $name => [$sold, $received, $months]) {
      $sales[$name] = new MarketplaceSale($name, $sold, $received, $received, leaseMonths: $months, status: 'done');
    }
    return $names === NULL ? $sales : array_intersect_key($sales, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function isPremium(array $current): bool {
    return !empty($current['premium']);
  }

}

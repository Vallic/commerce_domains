<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes Sedo's bulk upload file.
 *
 * Buy Now in euros at the catalog price times the price factor, with offers
 * from the base minimum. Atom premium names are left out, and so are
 * offers-only names: the file's selling option for those isn't documented.
 */
#[CommerceDomainsProvider(
  id: 'sedo',
  label: new TranslatableMarkup('Sedo'),
  description: new TranslatableMarkup("Exports listings as a CSV file for Sedo's bulk domain upload."),
)]
final class Sedo extends ListingPluginBase implements CsvExportInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price_factor' => 1.1] + array_diff_key(parent::defaultConfiguration(), ['premium_price_factor' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function csvHeader(string $file): array {
    return ['Domain Name', 'Selling Option', 'For Sale', 'Price', 'Minimum Price', 'Currency'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $price = (int) round($item->price('EUR') * $this->priceFactor($item));
    if ($item->atomPremium || $price <= 0 || !$item->terms->buyNow) {
      return NULL;
    }
    $minimum = $item->terms->offers ? $this->minimumOfferFor($item, $price, $this->minimumOffer($price), 'EUR') : $price;
    return [$item->title, 'BUY_NOW', 'YES', $price, $minimum, 'EUR'];
  }

}

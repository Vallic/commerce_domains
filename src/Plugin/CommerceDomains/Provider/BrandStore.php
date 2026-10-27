<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes the BrandStore listing upload file.
 *
 * Buy It Now at the catalog's dollar price times the price factor, with
 * offers from the base minimum (the full price for Atom premium names). The
 * file has no offers-only setting, so those names are left out.
 */
#[CommerceDomainsProvider(
  id: 'brandstore',
  label: new TranslatableMarkup('BrandStore'),
  description: new TranslatableMarkup('Exports listings as a CSV file for the BrandStore marketplace.'),
)]
final class BrandStore extends ListingPluginBase implements CsvExportInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price_factor' => 1.1] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function csvHeader(string $file): array {
    return ['domain', 'minPrice', 'buyItNowPrice'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $price = (int) round($item->price('USD') * $this->priceFactor($item));
    if ($price <= 0 || !$item->terms->buyNow) {
      return NULL;
    }
    $minimum = $item->terms->offers ? $this->minimumOfferFor($item, $price, $item->atomPremium ? $price : $this->minimumOffer($price)) : $price;
    return [$item->title, $minimum, $price];
  }

}

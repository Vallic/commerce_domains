<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes the DaaZ listing upload file.
 *
 * The catalog's dollar price times the price factor, with offers from the
 * base minimum (the full price for Atom premium names). The file has no
 * offers-only setting, so those names are left out.
 */
#[CommerceDomainsProvider(
  id: 'daaz',
  label: new TranslatableMarkup('DaaZ'),
  description: new TranslatableMarkup('Exports listings as a CSV file for the DaaZ marketplace.'),
)]
final class Daaz extends ListingPluginBase implements CsvExportInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price_factor' => 1.05] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function csvHeader(string $file): array {
    return ['Domain', 'Price', 'Min. Offer', 'Currency'];
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
    return [$item->title, $price, $minimum, 'USD'];
  }

}

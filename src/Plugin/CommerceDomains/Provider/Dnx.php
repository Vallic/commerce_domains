<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes the DNX listing upload file.
 *
 * Asking price at the catalog's dollar price times the price factor, a
 * reserve of 80%, and offers from the base minimum (the full price for Atom
 * premium names). The file has no offers-only setting, so those names are
 * left out.
 */
#[CommerceDomainsProvider(
  id: 'dnx',
  label: new TranslatableMarkup('DNX'),
  description: new TranslatableMarkup('Exports listings as a CSV file for the DNX marketplace.'),
)]
final class Dnx extends ListingPluginBase implements CsvExportInterface {

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
    return ['Domain', 'Asking Price', 'Reserve Price', 'Min Offer'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $usd_price = $item->price('USD') * $this->priceFactor($item);
    $price = (int) round($usd_price);
    if ($price <= 0 || !$item->terms->buyNow) {
      return NULL;
    }
    // Without offers, nothing below the price is taken.
    $offers = $item->terms->offers;
    $reserve = $offers ? $this->floorPriceFor($item, $price, (int) ($usd_price * 0.8)) : $price;
    $minimum = $offers ? $this->minimumOfferFor($item, $price, $item->atomPremium ? $price : $this->minimumOffer($price)) : $price;
    return [$item->title, $price, $reserve, $minimum];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes the NameClub listing upload file.
 *
 * Buy Now at the catalog's dollar price times the price factor, with offers
 * from the base minimum (the full price for Atom premium names), the
 * description and the for-sale lander. Domains registered at Dynadot are
 * left out: Dynadot's own marketplace listings already appear on NameClub.
 * The file has no offers-only setting, so those names are left out too.
 */
#[CommerceDomainsProvider(
  id: 'nameclub',
  label: new TranslatableMarkup('NameClub'),
  description: new TranslatableMarkup("Exports listings as a CSV file for NameClub, Dynadot's premium marketplace, for domains registered elsewhere."),
)]
final class NameClub extends ListingPluginBase implements CsvExportInterface {

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
    return ['Domain', 'Currency', 'Buy Now Price', 'Enable Offers', 'Minimum Offer', 'Description', 'For-Sale Lander'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $usd_price = $item->price('USD') * $this->priceFactor($item);
    // Whole dollars, with the cents dropped rather than rounded.
    $price = (int) $usd_price;
    if ($item->registrarPlugin === 'dynadot' || $price <= 0 || !$item->terms->buyNow) {
      return NULL;
    }
    $offers = $item->terms->offers;
    $rule = $item->atomPremium ? $price : (int) ($price < 10000 ? $usd_price * 0.5 : $usd_price * 0.25);
    $minimum = $offers ? $this->minimumOfferFor($item, $price, $rule) : $price;
    // Descriptions go without full stops.
    $description = self::csvText($item->description, ['.']);
    return [$item->title, 'USD', $price, $offers ? 'Yes' : 'No', $minimum, $description, 'Yes'];
  }

}

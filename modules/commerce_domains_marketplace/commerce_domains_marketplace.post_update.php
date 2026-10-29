<?php

/**
 * @file
 * Post update functions for Commerce Domains Marketplace.
 */

declare(strict_types=1);

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;

/**
 * Records the sales marketplaces showed before, for domains off sale here.
 *
 * Syncs left those listings alone: as mid-sale, or as left to the
 * marketplace (Atom premium). Recording them keeps the next sync from
 * reporting old sales as new. Domains still for sale here are left for the
 * next sync, which takes them off sale and reports the sale.
 */
function commerce_domains_marketplace_post_update_record_sales(): PluralTranslatableMarkup {
  $database = \Drupal::database();
  $entity_type_manager = \Drupal::entityTypeManager();
  $products = $entity_type_manager->getStorage('commerce_product');
  $availability = \Drupal::service(Availability::class);
  $for_sale = function (ProductInterface $product) use ($availability): bool {
    foreach ($product->getVariations() as $variation) {
      if ($availability->isForSale($product, $variation)) {
        return TRUE;
      }
    }
    return FALSE;
  };

  $count = 0;
  /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
  foreach ($entity_type_manager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof MarketplaceInterface) {
      continue;
    }
    // Whatever the row's status: the next sync counts a sale on any listing
    // of ours, unmanaged ones included.
    $rows = $database->select(ListingRecorder::TABLE, 'l')
      ->fields('l')
      ->condition('provider', $provider->id())
      ->condition('status', ListingRecorder::SOLD, '<>')
      ->isNull('sold')
      ->isNotNull('listing')
      ->execute()
      ->fetchAll();
    foreach ($rows as $row) {
      $listing = json_decode((string) $row->listing, TRUE);
      $status = is_array($listing) ? $plugin->saleStatus($listing) : NULL;
      $product = $row->product_id ? $products->load($row->product_id) : NULL;
      if ($status === NULL || ($product instanceof ProductInterface && $for_sale($product))) {
        continue;
      }
      $database->update(ListingRecorder::TABLE)
        ->fields([
          'status' => ListingRecorder::SOLD,
          'note' => $status,
          // When the row last changed, about when the sale showed.
          'sold' => (int) $row->changed ?: \Drupal::time()->getRequestTime(),
        ])
        ->condition('provider', $row->provider)
        ->condition('domain', $row->domain)
        ->execute();
      $count++;
    }
  }
  return \Drupal::translation()->formatPlural($count, 'Recorded 1 sale the marketplaces showed before.', 'Recorded @count sales the marketplaces showed before.');
}

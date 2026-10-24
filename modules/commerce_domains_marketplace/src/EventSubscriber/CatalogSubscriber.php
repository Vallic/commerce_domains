<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\EventSubscriber;

use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_pricelist\Event\PriceListItemEvent;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\Event\ProductEvent;
use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Schedules marketplace syncs when domains are added, changed or removed.
 */
class CatalogSubscriber implements EventSubscriberInterface {

  public function __construct(protected SyncScheduler $scheduler) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProductEvents::PRODUCT_INSERT => 'onProductSave',
      ProductEvents::PRODUCT_UPDATE => 'onProductSave',
      ProductEvents::PRODUCT_DELETE => 'onProductDelete',
      ProductEvents::PRODUCT_VARIATION_INSERT => 'onVariationChange',
      ProductEvents::PRODUCT_VARIATION_UPDATE => 'onVariationChange',
      ProductEvents::PRODUCT_VARIATION_DELETE => 'onVariationChange',
      // Price list items, by name: Commerce Pricelist is optional.
      'commerce_pricelist.commerce_pricelist_item.insert' => 'onPriceListItemChange',
      'commerce_pricelist.commerce_pricelist_item.update' => 'onPriceListItemChange',
      'commerce_pricelist.commerce_pricelist_item.delete' => 'onPriceListItemChange',
    ];
  }

  /**
   * Re-checks a saved domain; a renamed one also drops its old name.
   */
  public function onProductSave(ProductEvent $event): void {
    $product = $event->getProduct();
    if (!$name = $this->domain($product)) {
      return;
    }
    $this->scheduler->changed($name);

    $original = $product->getOriginal();
    $original_name = $original ? $this->domain($original) : NULL;
    if ($original_name !== NULL && $original_name !== $name) {
      $this->scheduler->removed($original_name);
    }
  }

  /**
   * Removes a deleted domain's listings.
   */
  public function onProductDelete(ProductEvent $event): void {
    if ($name = $this->domain($event->getProduct())) {
      $this->scheduler->removed($name);
    }
  }

  /**
   * Re-checks the domain of a changed variation: price, stock, status.
   */
  public function onVariationChange(ProductVariationEvent $event): void {
    if (($product = $event->getProductVariation()->getProduct()) && ($name = $this->domain($product))) {
      $this->scheduler->changed($name);
    }
  }

  /**
   * Re-checks the domain of a changed price list item.
   *
   * Whether a marketplace uses that list is left to the sync, which only
   * pushes what differs.
   */
  public function onPriceListItemChange(PriceListItemEvent $event): void {
    $variation = $event->getPriceListItem()->getPurchasableEntity();
    if ($variation instanceof ProductVariationInterface && ($product = $variation->getProduct()) && ($name = $this->domain($product))) {
      $this->scheduler->changed($name);
    }
  }

  /**
   * Returns a product's domain, or NULL when it isn't a domain product.
   */
  protected function domain(ProductInterface $product): ?string {
    return $product->hasField('domain') ? ($product->get('domain')->value ?: NULL) : NULL;
  }

}

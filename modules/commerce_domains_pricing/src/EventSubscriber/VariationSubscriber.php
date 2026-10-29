<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_pricing\EventSubscriber;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains_pricing\CurrencyPrices;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prices domains saved without a price, and keeps their currency prices.
 */
class VariationSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected PriceRules $priceRules,
    protected CurrencyPrices $currencyPrices,
    protected Catalog $catalog,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProductEvents::PRODUCT_VARIATION_PRESAVE => 'onPresave',
      // The price list needs the variation's ID.
      ProductEvents::PRODUCT_VARIATION_INSERT => 'onSave',
      ProductEvents::PRODUCT_VARIATION_UPDATE => 'onSave',
    ];
  }

  /**
   * Fills an empty price from the rules, then the currency price fields.
   *
   * Syncing saves, such as migrations, keep what they bring.
   */
  public function onPresave(ProductVariationEvent $event): void {
    $variation = $event->getProductVariation();
    if ($variation->isSyncing() || !$this->isDomain($variation)) {
      return;
    }
    // A new domain's variation is saved before its product, and again once
    // the product refers to it: the rules apply then.
    $product = $variation->getProduct();
    if (!$variation->getPrice() && $product) {
      $atom_premium = $product->hasField('atom_premium') && (bool) $product->get('atom_premium')->value;
      if ($price = $this->priceRules->price($product->getTitle(), $atom_premium)) {
        $variation->setPrice($price);
      }
    }
    $this->currencyPrices->updateFields($variation);
  }

  /**
   * Brings the variation's items in the price list in line.
   */
  public function onSave(ProductVariationEvent $event): void {
    $variation = $event->getProductVariation();
    if ($this->isDomain($variation)) {
      $this->currencyPrices->updatePriceList($variation);
    }
  }

  /**
   * Whether a variation is a domain's.
   *
   * By its product, or by its type while it has none yet.
   */
  protected function isDomain(ProductVariationInterface $variation): bool {
    if ($product = $variation->getProduct()) {
      return $product->hasField('domain');
    }
    $types = $this->entityTypeManager->getStorage('commerce_product_type')->loadMultiple($this->catalog->productTypes());
    foreach ($types as $type) {
      /** @var \Drupal\commerce_product\Entity\ProductTypeInterface $type */
      if (in_array($variation->bundle(), $type->getVariationTypeIds(), TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Records a domain's sale on its product: when, for how much, and where.
 *
 * A sale on this site comes from its order, which a cancelled order undoes.
 * A sale a marketplace reports gets the date and the marketplace; the owner
 * enters what it brought. Sale records aren't edits, so the product keeps
 * its changed time.
 */
class SaleRecorder {

  /**
   * Records the domains of an order as sold on this site.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $time
   *   When they sold. A domain already recorded for this order keeps its
   *   time and only gets the order's current price.
   *
   * @return array<int, array{product: \Drupal\commerce_product\Entity\ProductInterface, price: \Drupal\commerce_price\Price|null}>
   *   The order's domains and what each sold for, keyed by product ID.
   */
  public function recordOrder(OrderInterface $order, int $time): array {
    $sold = [];
    foreach (self::domainItems($order) as [$item, $product]) {
      $price = self::itemPrice($item);
      $sold[(int) $product->id()] = ['product' => $product, 'price' => $price];
      if (!$product->hasField('domain_sold')) {
        continue;
      }
      if (!$this->isSaleOf($product, $order)) {
        $product->set('domain_sold', $time)
          ->set('domain_sold_via', NULL)
          ->set('domain_sale_order', $order->id());
      }
      $product->set('domain_sale_price', $price);
      $this->save($product);
    }
    return $sold;
  }

  /**
   * Updates the price of an order's domains, e.g. once an offer settles.
   */
  public function refreshOrder(OrderInterface $order): void {
    foreach (self::domainItems($order) as [$item, $product]) {
      $price = self::itemPrice($item);
      $old = Costs::price($product, 'domain_sale_price');
      if ($price && $this->isSaleOf($product, $order) && (!$old || !$old->equals($price))) {
        $product->set('domain_sale_price', $price);
        $this->save($product);
      }
    }
  }

  /**
   * Undoes the sale of a cancelled order's domains.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface[]
   *   The domains whose sale was undone.
   */
  public function cancelOrder(OrderInterface $order): array {
    $undone = [];
    foreach (self::domainItems($order) as [, $product]) {
      if ($this->isSaleOf($product, $order)) {
        foreach (['domain_sold', 'domain_sale_price', 'domain_sold_via', 'domain_sale_order'] as $field) {
          $product->set($field, NULL);
        }
        $this->save($product);
        $undone[] = $product;
      }
    }
    return $undone;
  }

  /**
   * Records a sale a marketplace reported.
   *
   * A domain already recorded as sold keeps that sale: it may be the same
   * one, reported late.
   *
   * @return bool
   *   Whether the sale was recorded.
   */
  public function recordElsewhere(ProductInterface $product, ProviderInterface $provider, int $time): bool {
    if (!$product->hasField('domain_sold') || !$product->get('domain_sold')->isEmpty()) {
      return FALSE;
    }
    $product->set('domain_sold', $time)
      ->set('domain_sold_via', $provider->id())
      ->set('domain_sale_order', NULL);
    $this->save($product);
    return TRUE;
  }

  /**
   * Returns the domain items of an order, with their products.
   *
   * @return array<int, array{0: \Drupal\commerce_order\Entity\OrderItemInterface, 1: \Drupal\commerce_product\Entity\ProductInterface}>
   *   Pairs of order item and product.
   */
  public static function domainItems(OrderInterface $order): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
      if ($product && $product->hasField('domain')) {
        $items[] = [$item, $product];
      }
    }
    return $items;
  }

  /**
   * Returns what an order item sold for: after discounts, before tax and fees.
   */
  public static function itemPrice(OrderItemInterface $item): ?Price {
    return $item->getAdjustedTotalPrice(['promotion']) ?? $item->getTotalPrice();
  }

  /**
   * Whether the domain's recorded sale is this order.
   */
  protected function isSaleOf(ProductInterface $product, OrderInterface $order): bool {
    return $product->hasField('domain_sale_order') && (int) $product->get('domain_sale_order')->value === (int) $order->id();
  }

  /**
   * Saves a product without counting it as an edit.
   */
  protected function save(ProductInterface $product): void {
    $syncing = $product->isSyncing();
    $product->setSyncing(TRUE)->save();
    $product->setSyncing($syncing);
  }

}

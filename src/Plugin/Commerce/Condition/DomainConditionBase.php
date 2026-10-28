<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Base for conditions on the domains being bought.
 *
 * Each condition is one class for order items, as promotion offers use, and
 * a subclass for orders, as promotions, payment gateways and shipping methods
 * use: an order matches when any of its domains does.
 */
abstract class DomainConditionBase extends ConditionBase {

  /**
   * Whether a domain matches.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The domain's variation; its product holds the domain.
   */
  abstract protected function matches(ProductVariationInterface $variation): bool;

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    $items = $entity instanceof OrderInterface ? $entity->getItems() : [$entity];
    foreach ($items as $item) {
      if ($item instanceof OrderItemInterface && ($variation = $this->domainVariation($item)) && $this->matches($variation)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns an order item's variation, when it is a domain.
   */
  protected function domainVariation(OrderItemInterface $item): ?ProductVariationInterface {
    $variation = $item->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface || !($product = $variation->getProduct()) || !$product->hasField('domain')) {
      return NULL;
    }
    return $variation;
  }

}

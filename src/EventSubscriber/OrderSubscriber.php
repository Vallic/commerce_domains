<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\EventSubscriber;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Takes the domains of a placed order off sale.
 *
 * On placing rather than on payment: a bank transfer can take days, and the
 * domain mustn't sell twice meanwhile. Listens by event name, so it does
 * nothing without commerce_order.
 *
 * The variation saves this makes are what removes the domain's marketplace
 * listings: with automatic pushes, Commerce Domains Marketplace queues the
 * removals at once; otherwise the next sync makes them.
 */
class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(protected Availability $availability) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['commerce_order.place.post_transition' => 'onPlace'];
  }

  /**
   * Marks each domain in the order sold.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }
      $product = $variation->getProduct();
      if ($product && $product->hasField('domain')) {
        // The variation bought stays sold even if its product is unpublished
        // now; any other variation would keep the domain for sale.
        $this->availability->markSold($variation);
        $this->availability->markProductSold($product);
      }
    }
  }

}

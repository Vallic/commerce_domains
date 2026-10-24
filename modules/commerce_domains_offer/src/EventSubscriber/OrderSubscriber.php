<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\EventSubscriber;

use Drupal\commerce_domains_offer\OfferManager;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks offers purchased when their order is placed.
 */
final class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected OfferManager $offerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['commerce_order.place.post_transition' => 'onPlace'];
  }

  /**
   * Marks the order's offers purchased.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $this->offerManager->orderPlaced($order);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\EventSubscriber;

use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Starts handovers when an order is paid in full, and cancels them with it.
 *
 * Commerce announces the payment once, before the order is saved, so the
 * handovers start once the save is done: an order saved for the first time
 * has no ID before that, and a failed save starts nothing. An order paid
 * before it was placed starts on placing instead.
 *
 * Only that moment starts handovers, never a later save, so orders paid
 * before this module was installed are left alone.
 *
 * The order is saved by whatever recorded the payment, often a gateway's
 * webhook, so a handover that fails to start is logged rather than failing
 * that save; the seller starts it on the order's Handover tab.
 */
final class OrderSubscriber implements EventSubscriberInterface {

  /**
   * Orders paid in the save under way, keyed by UUID.
   *
   * @var array<string, true>
   */
  protected array $paid = [];

  public function __construct(
    protected HandoverManager $handoverManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => 'onPaid',
      OrderEvents::ORDER_INSERT => 'onSave',
      OrderEvents::ORDER_UPDATE => 'onSave',
      OrderEvents::ORDER_DELETE => 'onDelete',
      'commerce_order.place.post_transition' => 'onPlace',
      'commerce_order.cancel.post_transition' => 'onCancel',
    ];
  }

  /**
   * Deletes the handovers of a deleted order, as Commerce does its items.
   */
  public function onDelete(OrderEvent $event): void {
    $this->handoverManager->orderDeleted($event->getOrder());
  }

  /**
   * Notes an order the save under way pays in full.
   */
  public function onPaid(OrderEvent $event): void {
    $this->paid[$event->getOrder()->uuid()] = TRUE;
  }

  /**
   * Starts the handovers of an order the save paid in full.
   */
  public function onSave(OrderEvent $event): void {
    $order = $event->getOrder();
    if (isset($this->paid[$order->uuid()])) {
      unset($this->paid[$order->uuid()]);
      $this->start($order);
    }
  }

  /**
   * Starts the handovers of an order that was paid before it was placed.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface && $order->isPaid()) {
      $this->start($order);
    }
  }

  /**
   * Cancels the open handovers of a canceled order.
   */
  public function onCancel(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }
    try {
      $this->handoverManager->orderCanceled($order);
    }
    catch (\Exception $e) {
      $this->logger->error('Canceling the handover of canceled order @order failed: @error. Check its Handover tab.', [
        '@order' => $order->getOrderNumber() ?: $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Starts the handovers of a paid order, logging a failure.
   */
  protected function start(OrderInterface $order): void {
    try {
      $this->handoverManager->startPaid($order);
    }
    catch (\Exception $e) {
      $this->logger->error('Starting the handover of paid order @order failed: @error. Check its Handover tab.', [
        '@order' => $order->getOrderNumber() ?: $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

}

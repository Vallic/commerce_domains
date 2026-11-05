<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\EventSubscriber;

use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Completes a paid order once its domains have been handed over.
 *
 * With commerce_domains_handover.settings:complete_orders. Orders that stay
 * open after placing, so that an unpaid one can be canceled, would otherwise
 * wait for the seller to complete them with nothing left to do. An order paid
 * through Escrow.com is left to Escrow.com's webhooks.
 */
final class OrderCompletionSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HandoverManager $handoverManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_domains_handover.complete.post_transition' => 'onHandoverClosed',
      'commerce_domains_handover.cancel.post_transition' => 'onHandoverClosed',
    ];
  }

  /**
   * Completes the order when this was the last of its open handovers.
   */
  public function onHandoverClosed(WorkflowTransitionEvent $event): void {
    if (!$this->configFactory->get('commerce_domains_handover.settings')->get('complete_orders')) {
      return;
    }
    $handover = $event->getEntity();
    if (!$handover instanceof HandoverInterface || !$handover->getOrderId()) {
      return;
    }
    /** @var \Drupal\commerce_order\OrderStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $order_id = $handover->getOrderId();
    // The handover must not fail over its order: the domain has moved already.
    try {
      $order = $storage->loadForUpdate($order_id);
      if ($order && $this->isHandedOver($order, $handover)) {
        $this->complete($order);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Completing order @order after its handover failed: @error', [
        '@order' => $order_id,
        '@error' => $e->getMessage(),
      ]);
    }
    finally {
      $storage->releaseLock($order_id);
    }
  }

  /**
   * Whether a paid order has at least one domain handed over and none to go.
   */
  protected function isHandedOver(OrderInterface $order, HandoverInterface $closed): bool {
    if (!$order->isPaid() || $this->handoverManager->isEscrow($order)) {
      return FALSE;
    }
    // The one that just closed may still be cached in its old state.
    $handovers = [$closed->id() => $closed] + $this->handoverManager->forOrder($order);
    $transferred = FALSE;
    foreach ($handovers as $handover) {
      if ($handover->isOpen()) {
        return FALSE;
      }
      $transferred = $transferred || $handover->getState()->getId() === 'transferred';
    }
    return $transferred;
  }

  /**
   * Applies whichever transition of the order's workflow completes it.
   */
  protected function complete(OrderInterface $order): void {
    foreach ($order->getState()->getTransitions() as $transition) {
      if ($transition->getToState()->getId() === 'completed') {
        $order->getState()->applyTransition($transition);
        $order->save();
        return;
      }
    }
  }

}

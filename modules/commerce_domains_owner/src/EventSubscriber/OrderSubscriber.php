<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\EventSubscriber;

use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the sales of domains on this site, and tells the owner.
 *
 * A domain sells when its order is placed, or, for an offer negotiated on
 * Escrow.com, when both sides agree there; Escrow.com's payment approval
 * then brings the agreed price. Cancelling the order undoes the sale.
 * Listens by event name, so it does nothing without commerce_order.
 */
class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected SaleRecorder $recorder,
    protected Notifier $notifier,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => 'onPlace',
      // Transitions of Commerce Escrow's order workflow.
      'commerce_order.offer_accepted.post_transition' => 'onOfferAccepted',
      'commerce_order.payment_approved.post_transition' => 'onPaymentApproved',
      'commerce_order.cancel.post_transition' => 'onCancel',
    ];
  }

  /**
   * Records the order's domains as sold.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->sell($order, $order->getPlacedTime() ?: $this->time->getRequestTime());
    }
  }

  /**
   * Records the domain of an offer agreed on Escrow.com as sold.
   */
  public function onOfferAccepted(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->sell($order, $this->time->getRequestTime());
    }
  }

  /**
   * Takes the agreed price once Escrow.com approves the payment.
   */
  public function onPaymentApproved(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->guard(fn() => $this->recorder->refreshOrder($order), $order);
    }
  }

  /**
   * Undoes the sale of a cancelled order's domains.
   */
  public function onCancel(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->guard(fn() => $this->recorder->cancelOrder($order), $order);
    }
  }

  /**
   * Records the sale and mails the owner.
   */
  protected function sell(OrderInterface $order, int $time): void {
    $this->guard(function () use ($order, $time): void {
      if ($sold = $this->recorder->recordOrder($order, $time)) {
        $this->notifier->sold($order, $sold);
      }
    }, $order);
  }

  /**
   * Runs a step of the bookkeeping without letting it stop the order.
   */
  protected function guard(callable $step, OrderInterface $order): void {
    try {
      $step();
    }
    catch (\Exception $e) {
      $this->logger->error('Recording the sale of order @order failed: @error', [
        '@order' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

}

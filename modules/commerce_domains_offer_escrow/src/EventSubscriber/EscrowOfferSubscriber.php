<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer_escrow\EventSubscriber;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer_escrow\EscrowOfferStarter;
use Drupal\commerce_escrow\Event\EscrowEvents;
use Drupal\commerce_escrow\Event\EscrowWebhookEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Follows an Escrow.com negotiation on its offer.
 *
 * Escrow.com emails both sides, so these changes send nothing.
 */
final class EscrowOfferSubscriber implements EventSubscriberInterface {

  // The webhooks that mean both sides have settled on a price.
  protected const SETTLED_EVENTS = ['offer_accepted', 'payment_sent', 'payment_received', 'payment_approved'];

  public function __construct(
    protected EscrowOfferStarter $starter,
    protected Availability $availability,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.offer_accepted.post_transition' => 'onOfferAccepted',
      'commerce_order.payment_approved.post_transition' => 'onPaymentApproved',
      EscrowEvents::ESCROW_WEBHOOK => 'onWebhook',
    ];
  }

  /**
   * Accepts the offer once both sides agree on Escrow.com.
   *
   * The domain is sold from then on, as the buyer is paying for it.
   */
  public function onOfferAccepted(WorkflowTransitionEvent $event): void {
    $offer = $this->offer($event);
    if ($offer && $this->apply($offer, 'accept')) {
      $offer->save();
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $this->takeOffSale($order);
  }

  /**
   * Marks the offer purchased once Escrow.com approves the payment.
   */
  public function onPaymentApproved(WorkflowTransitionEvent $event): void {
    $offer = $this->offer($event);
    if ($offer) {
      // A missed offer_accepted webhook mustn't leave it pending.
      $this->apply($offer, 'accept');
      if ($this->apply($offer, 'purchase')) {
        $offer->save();
      }
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $event->getEntity();
      $this->takeOffSale($order);
    }
  }

  /**
   * Follows a webhook on the offer of its order.
   *
   * The first webhook means the buyer has made the offer, so it records it. By
   * a payment both sides have settled, whether or not offer_accepted arrived,
   * so the domain mustn't stay for sale. The agreed amount is on the order by
   * payment_sent, and a cancelled negotiation declines the offer.
   */
  public function onWebhook(EscrowWebhookEvent $event): void {
    $order = $event->getOrder();
    $offer = $this->starter->offerFor($order) ?? $this->starter->recordOffer($order);
    if (!$offer) {
      return;
    }
    $payload = $event->getPayload();
    $name = $payload['event'] ?? NULL;
    $changed = FALSE;
    if (in_array($name, self::SETTLED_EVENTS, TRUE)) {
      $changed = $this->apply($offer, 'accept');
      $this->takeOffSale($order);
    }
    if ($name === 'payment_sent' && ($total = $order->getTotalPrice())) {
      $offer->set('amount', $total);
      $changed = TRUE;
    }
    if ($name === 'cancel') {
      $changed = $this->apply($offer, 'decline') || $this->apply($offer, 'expire');
    }
    if ($changed) {
      $offer->save();
    }
  }

  /**
   * Takes the domains of an order off sale.
   *
   * As when an order is placed: the variation bought, and any other variation
   * that would keep the domain for sale.
   */
  protected function takeOffSale(OrderInterface $order): void {
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
      if ($product && $product->hasField('domain')) {
        $this->availability->markSold($variation);
        $this->availability->markProductSold($product);
      }
    }
  }

  /**
   * Returns the offer of the order in a transition event.
   */
  protected function offer(WorkflowTransitionEvent $event): ?OfferInterface {
    $order = $event->getEntity();
    return $order instanceof OrderInterface ? $this->starter->offerFor($order) : NULL;
  }

  /**
   * Applies a transition when the offer's state allows it.
   *
   * @return bool
   *   Whether it was applied.
   */
  protected function apply(OfferInterface $offer, string $transition): bool {
    $state = $offer->getState();
    $allowed = array_key_exists($transition, $state->getTransitions());
    if ($allowed) {
      $state->applyTransitionById($transition);
    }
    return $allowed;
  }

}

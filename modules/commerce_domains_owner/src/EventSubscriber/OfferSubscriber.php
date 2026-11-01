<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\EventSubscriber;

use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tells the owner when an offer expires, and records offers paid elsewhere.
 *
 * The offers module tells the buyer, and the owner about new offers and
 * answered counter-offers; an offer running out is the one news it leaves
 * to the owner to find. Listens by event name, so it does nothing without
 * Commerce Domains Offers.
 */
class OfferSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected Notifier $notifier,
    protected SaleRecorder $recorder,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_domains_offer.expire.post_transition' => 'onExpire',
      'commerce_domains_offer.purchase.post_transition' => 'onPurchase',
    ];
  }

  /**
   * Records the sale of an offer paid through a provider's payment link.
   *
   * An offer paid at checkout is recorded from its order.
   */
  public function onPurchase(WorkflowTransitionEvent $event): void {
    $offer = $event->getEntity();
    if (!$offer instanceof OfferInterface || $offer->getOrder() || !($provider = $offer->getPaymentLinkProvider())) {
      return;
    }
    $product = $offer->getVariation()?->getProduct();
    $amount = $offer->getAgreedAmount();
    if ($product && $amount) {
      $this->recorder->recordSale($product, $provider, $amount, $this->time->getRequestTime(), $offer->getPaymentLinkLeaseMonths());
    }
  }

  /**
   * Mails the owner about the expired offer.
   */
  public function onExpire(WorkflowTransitionEvent $event): void {
    $offer = $event->getEntity();
    if ($offer instanceof OfferInterface) {
      // Still the state it left: the field's original value is only reset
      // after this event.
      $this->notifier->offerExpired($offer, (string) $event->getField()->getOriginalId());
    }
  }

}

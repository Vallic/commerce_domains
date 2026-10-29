<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\EventSubscriber;

use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tells the owner when an offer expires.
 *
 * The offers module tells the buyer, and the owner about new offers and
 * answered counter-offers; an offer running out is the one news it leaves
 * to the owner to find. Listens by event name, so it does nothing without
 * Commerce Domains Offers.
 */
class OfferSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected Notifier $notifier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['commerce_domains_offer.expire.post_transition' => 'onExpire'];
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

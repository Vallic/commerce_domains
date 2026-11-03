<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\EventSubscriber;

use Drupal\commerce_domains_checkout\UnpaidOrders;
use Drupal\commerce_payment\Event\PaymentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Notices money that arrives for a canceled order.
 *
 * Listens by event name, as Commerce Payment is optional here.
 */
final class LatePaymentSubscriber implements EventSubscriberInterface {

  public function __construct(protected UnpaidOrders $unpaidOrders) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_payment.commerce_payment.insert' => 'onPayment',
      'commerce_payment.commerce_payment.update' => 'onPayment',
    ];
  }

  /**
   * Tells the owner if the payment is for a canceled order.
   */
  public function onPayment(PaymentEvent $event): void {
    $this->unpaidOrders->latePayment($event->getPayment());
  }

}

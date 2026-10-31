<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\EventSubscriber;

use Drupal\commerce_domains_lease\LeaseCalculator;
use Drupal\commerce_domains_lease\LeaseManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Pays a leased order's down payment at checkout, then starts the lease.
 *
 * A leased order offers only the gateways that can charge a saved card
 * later (gateway_plugins in the settings). With Stripe, the checkout charges
 * the down payment rather than the order's balance, and saves the card for
 * payments made without the buyer; the order is placed with the rest still
 * to pay, which the lease charges monthly.
 */
final class CheckoutSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected LeaseCalculator $calculator,
    protected LeaseManager $leaseManager,
    protected MinorUnitsConverterInterface $minorUnitsConverter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PaymentEvents::FILTER_PAYMENT_GATEWAYS => 'onFilterGateways',
      // By name: Commerce Stripe is optional.
      'commerce_stripe.payment_intent.create' => 'onStripeIntent',
      'commerce_order.place.post_transition' => 'onPlace',
    ];
  }

  /**
   * Offers a leased order only the gateways a lease can be paid with.
   */
  public function onFilterGateways(FilterPaymentGatewaysEvent $event): void {
    if (LeaseCalculator::chosenMonths($event->getOrder()) === NULL) {
      return;
    }
    $allowed = array_flip($this->calculator->gateways());
    $event->setPaymentGateways(array_intersect_key($event->getPaymentGateways(), $allowed));
  }

  /**
   * Makes the checkout's Stripe payment the down payment, and keeps the card.
   *
   * @param object $event
   *   Commerce Stripe's PaymentIntentCreateEvent.
   */
  public function onStripeIntent(object $event): void {
    if (!method_exists($event, 'getOrder') || !method_exists($event, 'getIntentAttributes')) {
      return;
    }
    $order = $event->getOrder();
    $attributes = $event->getIntentAttributes();
    // Only the checkout's own payment: later ones are charged off session.
    if (!$order instanceof OrderInterface || $order->getState()->getId() !== 'draft' || !empty($attributes['off_session'])) {
      return;
    }
    $months = LeaseCalculator::chosenMonths($order);
    $plan = $months ? $this->calculator->plan($order, $months) : NULL;
    if (!$plan) {
      return;
    }
    $attributes['amount'] = $this->minorUnitsConverter->toMinorUnits($plan->downPayment);
    $attributes['setup_future_usage'] = 'off_session';
    $attributes['metadata']['lease_months'] = $plan->months;
    $event->setIntentAttributes($attributes);
  }

  /**
   * Starts the lease of a placed order.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface && LeaseCalculator::chosenMonths($order) !== NULL) {
      $this->leaseManager->start($order);
    }
  }

}

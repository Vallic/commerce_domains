<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\EventSubscriber;

use Drupal\commerce_domains_lease\LeaseCalculator;
use Drupal\commerce_domains_lease\LeaseManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_payment\Event\PaymentEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Pays a leased order's down payment at checkout, then starts the lease.
 *
 * A leased order offers only the gateways a lease can be paid with: those
 * that can charge a saved card later (gateway_plugins in the settings), and
 * those the buyer pays each payment through (manual_gateway_plugins). With
 * Stripe, the checkout charges the down payment rather than the order's
 * balance, and saves the card for payments made without the buyer. By bank
 * transfer, the payment the buyer is asked for is the down payment; with
 * Wise, the pay link's amount is. The order is placed with the rest still to
 * pay, which the lease charges, or asks for, monthly.
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
      PaymentEvents::PAYMENT_PRESAVE => 'onPaymentPresave',
      PaymentEvents::PAYMENT_INSERT => 'onPaymentSaved',
      PaymentEvents::PAYMENT_UPDATE => 'onPaymentSaved',
      // By name: Commerce Wise is optional.
      'commerce_wise.quick_pay_link' => 'onWiseLink',
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
   * Makes a leased order's payment by hand at checkout the down payment.
   *
   * A manual gateway asks for the order's balance at checkout, as a pending
   * payment the owner marks received; for a lease that is the down payment.
   */
  public function onPaymentPresave(PaymentEvent $event): void {
    $payment = $event->getPayment();
    $order = $payment->getOrder();
    $gateway = $payment->getPaymentGateway();
    if (!$payment->isNew() || $payment->getState()->getId() !== 'pending' || !$order || $order->getState()->getId() !== 'draft' || !$gateway || !$this->calculator->isManual($gateway)) {
      return;
    }
    $months = LeaseCalculator::chosenMonths($order);
    $plan = $months ? $this->calculator->plan($order, $months) : NULL;
    if ($plan) {
      $payment->setAmount($plan->downPayment);
    }
  }

  /**
   * Counts a payment received on a manual lease's order.
   */
  public function onPaymentSaved(PaymentEvent $event): void {
    $payment = $event->getPayment();
    $order = $payment->getOrder();
    if (!$order || !in_array($payment->getState()->getId(), ['completed', 'partially_refunded', 'refunded'], TRUE)) {
      return;
    }
    $lease = $this->leaseManager->forOrder($order);
    if ($lease && $lease->isManual()) {
      $this->leaseManager->record($lease);
    }
  }

  /**
   * Asks for the payment due, not the order's total, in a Wise pay link.
   *
   * @param object $event
   *   Commerce Wise's QuickPayLinkEvent.
   */
  public function onWiseLink(object $event): void {
    if (!method_exists($event, 'getOrder') || !method_exists($event, 'setBalance')) {
      return;
    }
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }
    $lease = $this->leaseManager->forOrder($order);
    if ($lease) {
      if ($lease->isOpen()) {
        $event->setBalance($lease->getNextAmount());
      }
      return;
    }
    $months = $order->getState()->getId() === 'draft' ? LeaseCalculator::chosenMonths($order) : NULL;
    $plan = $months ? $this->calculator->plan($order, $months) : NULL;
    if ($plan) {
      $event->setBalance($plan->downPayment);
    }
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

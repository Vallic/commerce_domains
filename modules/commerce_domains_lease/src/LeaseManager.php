<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\Plugin\AdvancedQueue\JobType\LeaseChargeJob;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts leases, charges their monthly payments, and handles missed ones.
 *
 * Cron queues the leases with a payment due; each job charges one payment
 * to the lease's saved card, as a payment on the lease's order. A payment
 * that fails is retried every retry_days days, max_retries times; after
 * that the lease is in default and nothing more is charged until the owner
 * charges it by hand. The buyer hears about every payment and failure, the
 * owner about failures, defaults and completed leases.
 */
class LeaseManager {

  // The queue of lease payments.
  public const QUEUE = 'commerce_domains_lease';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LeaseCalculator $calculator,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Starts the lease of a placed order, once.
   *
   * @return \Drupal\commerce_domains_lease\Entity\LeaseInterface|null
   *   The lease, or NULL when the order isn't a lease.
   */
  public function start(OrderInterface $order): ?LeaseInterface {
    $months = LeaseCalculator::chosenMonths($order);
    $plan = $months ? $this->calculator->plan($order, $months) : NULL;
    if (!$plan || $this->forOrder($order)) {
      return NULL;
    }
    $item = $this->calculator->domainItem($order);
    $variation = $item?->getPurchasedEntity();
    $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
    $method = $order->get('payment_method')->entity;
    $gateway = $order->get('payment_gateway')->entity;
    $placed = $order->getPlacedTime() ?: $this->time->getRequestTime();
    /** @var \Drupal\commerce_domains_lease\Entity\LeaseInterface $lease */
    $lease = $this->entityTypeManager->getStorage('commerce_domains_lease')->create([
      'order_id' => $order->id(),
      'uid' => $order->getCustomerId(),
      'domain' => $product && !$product->get('domain')->isEmpty() ? $product->get('domain')->value : (string) $item?->getTitle(),
      'payment_method' => $method instanceof PaymentMethodInterface ? $method->id() : NULL,
      'payment_gateway' => $gateway instanceof PaymentGatewayInterface ? $gateway->id() : NULL,
      'months' => $plan->months,
      'total' => $plan->total,
      'down_payment' => $plan->downPayment,
      'installment' => $plan->installment,
      // What is on the order already: the down payment by card, or nothing
      // yet when the buyer pays it by bank transfer.
      'paid' => $this->paidOn($order) ?? new Price('0', $plan->total->getCurrencyCode()),
      'next_due' => self::addMonths($placed, 1),
    ]);
    $lease->save();
    // A card gateway without a saved card falls back to asking the buyer.
    if ($lease->isManual() && (!$gateway instanceof PaymentGatewayInterface || !$this->calculator->isManual($gateway))) {
      $this->logger->warning('Lease @lease of @domain has no saved card to charge the monthly payments to.', [
        '@lease' => $lease->id(),
        '@domain' => $lease->getDomain(),
      ]);
    }
    $this->mail('started', $lease);
    return $lease;
  }

  /**
   * Returns the lease of an order.
   */
  public function forOrder(OrderInterface $order): ?LeaseInterface {
    if ($order->isNew()) {
      return NULL;
    }
    $leases = $this->entityTypeManager->getStorage('commerce_domains_lease')->loadByProperties(['order_id' => $order->id()]);
    $lease = reset($leases);
    return $lease instanceof LeaseInterface ? $lease : NULL;
  }

  /**
   * Returns the IDs of the open leases with a payment due.
   *
   * @return int[]
   *   Lease IDs.
   */
  public function due(): array {
    $ids = $this->entityTypeManager->getStorage('commerce_domains_lease')->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', [LeaseInterface::ACTIVE, LeaseInterface::OVERDUE], 'IN')
      ->condition('next_due', $this->time->getRequestTime(), '<=')
      ->sort('next_due')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Queues the payments due, one job per lease.
   *
   * @return int
   *   How many were queued.
   */
  public function queueDue(): int {
    $queue = Queue::load(self::QUEUE);
    if (!$queue) {
      return 0;
    }
    $count = 0;
    foreach ($this->due() as $id) {
      $queue->enqueueJob(Job::create(LeaseChargeJob::ID, ['lease_id' => $id]));
      $count++;
    }
    return $count;
  }

  /**
   * Charges the next payment of a lease, or asks for it on a manual lease.
   *
   * @return bool
   *   Whether it was paid.
   */
  public function charge(LeaseInterface $lease): bool {
    if (!$lease->isOpen() && $lease->getState() !== LeaseInterface::DEFAULTED) {
      return FALSE;
    }
    if ($lease->isManual()) {
      $this->request($lease);
      return FALSE;
    }
    $order = $lease->getOrder();
    $method = $lease->getPaymentMethod();
    $amount = $lease->getNextAmount();
    if (!$order || !$amount->isPositive()) {
      $this->complete($lease);
      return FALSE;
    }
    $gateway = $method?->getPaymentGateway();
    $plugin = $gateway?->getPlugin();
    if (!$method || !$method->isReusable() || !$plugin instanceof SupportsStoredPaymentMethodsInterface) {
      $this->failed($lease, 'No saved card to charge.');
      return FALSE;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'state' => 'new',
      'amount' => $amount,
      'payment_gateway' => $gateway->id(),
      'payment_method' => $method->id(),
      'order_id' => $order->id(),
    ]);
    // Stripe charges the payment intent stored on the order, which is the
    // down payment's: a later payment needs one of its own.
    if ($order->getData('stripe_intent')) {
      $order->unsetData('stripe_intent')->save();
    }
    try {
      $plugin->createPayment($payment, TRUE);
    }
    catch (PaymentGatewayException $e) {
      $this->failed($lease, $e->getMessage());
      return FALSE;
    }

    $paid = $lease->getPaid()->add($amount);
    $lease->set('paid', $paid)
      ->set('payments_made', $lease->getPaymentsMade() + 1)
      ->set('failures', 0)
      ->set('last_error', NULL)
      ->set('state', LeaseInterface::ACTIVE)
      // From when it was due, so a late payment keeps the day of the month.
      ->set('next_due', self::addMonths($lease->getNextDue() ?? $this->time->getRequestTime(), 1));
    if ($lease->getRemaining()->isZero()) {
      $this->complete($lease);
    }
    else {
      $lease->save();
      $this->mail('paid', $lease, ['amount' => $amount]);
    }
    return TRUE;
  }

  /**
   * Asks the buyer of a manual lease for the payment due.
   *
   * The first time it is due the buyer gets the amount and how to pay it.
   * Unpaid after the retry days, it counts as a missed payment: the buyer is
   * reminded, and past the tries the lease is in default, as with a card.
   */
  public function request(LeaseInterface $lease): void {
    if (!$lease->isOpen() || !$lease->getRemaining()->isPositive()) {
      return;
    }
    if ($lease->getRequested() !== NULL) {
      $this->failed($lease, 'Not paid yet.');
      return;
    }
    $now = $this->time->getRequestTime();
    $retry = max(1, (int) $this->configFactory->get('commerce_domains_lease.settings')->get('retry_days'));
    $lease->set('requested', $now)->set('next_due', $now + $retry * 86400)->save();
    $this->mail('request', $lease, ['amount' => $lease->getNextAmount()]);
  }

  /**
   * Brings a manual lease up to date with the payments on its order.
   *
   * Whatever was paid counts, however it arrived: recorded by the owner,
   * received on the order's Payments tab, or reported by the gateway.
   */
  public function record(LeaseInterface $lease): void {
    $order = $lease->getOrder();
    $paid = $order ? $this->paidOn($order) : NULL;
    $closed = [LeaseInterface::COMPLETED, LeaseInterface::CANCELED];
    if (!$paid || !$lease->isManual() || in_array($lease->getState(), $closed, TRUE)) {
      return;
    }
    $before = $lease->getPaid();
    if ($paid->equals($before)) {
      return;
    }
    // The monthly payments the money covers, after the down payment.
    $made = 0;
    $after_down = $paid->subtract($lease->getDownPayment());
    if (!$after_down->isNegative() && $lease->getInstallment()->isPositive()) {
      $made = min($lease->getMonths(), (int) floor(((float) $after_down->getNumber() + 0.005) / (float) $lease->getInstallment()->getNumber()));
    }
    $lease->set('paid', $paid)
      ->set('payments_made', $made)
      ->set('failures', 0)
      ->set('last_error', NULL)
      ->set('requested', NULL)
      ->set('state', LeaseInterface::ACTIVE)
      // Due on the same day each month from the start, however late the
      // payments before it came.
      ->set('next_due', self::addMonths((int) ($order->getPlacedTime() ?: $lease->get('created')->value), $made + 1));
    if ($lease->getRemaining()->isZero()) {
      $this->complete($lease);
      return;
    }
    $lease->save();
    if ($paid->greaterThan($before)) {
      $this->mail('paid', $lease, ['amount' => $paid->subtract($before)]);
    }
  }

  /**
   * Records a payment the owner received for a manual lease.
   *
   * The payment the checkout left pending, as a bank transfer does, is the
   * one received; otherwise a new one is recorded.
   */
  public function received(LeaseInterface $lease, Price $amount): void {
    $order = $lease->getOrder();
    $gateway = $lease->getPaymentGateway();
    if (!$order || !$gateway || !$amount->isPositive()) {
      return;
    }
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = NULL;
    foreach ($storage->loadMultipleByOrder($order) as $candidate) {
      if ($candidate->getState()->getId() === 'pending' && $candidate->getPaymentGatewayId() === $gateway->id()) {
        $payment = $candidate;
        break;
      }
    }
    $payment ??= $storage->create([
      'payment_gateway' => $gateway->id(),
      'order_id' => $order->id(),
    ]);
    $payment->setAmount($amount);
    $payment->setState('completed');
    $payment->save();
    $this->record($lease);
  }

  /**
   * Returns what was paid on an order, refunds taken off.
   *
   * Summed from its payments rather than read from the order, whose total
   * paid is only brought up to date at the end of the request.
   */
  public function paidOn(OrderInterface $order): ?Price {
    $total = NULL;
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    foreach ($storage->loadMultipleByOrder($order) as $payment) {
      if (in_array($payment->getState()->getId(), ['completed', 'partially_refunded'], TRUE) && $balance = $payment->getBalance()) {
        $total = $total ? $total->add($balance) : $balance;
      }
    }
    return $total;
  }

  /**
   * Stops a lease: nothing more is charged.
   */
  public function cancel(LeaseInterface $lease): void {
    $lease->set('state', LeaseInterface::CANCELED)->set('next_due', NULL)->save();
  }

  /**
   * Records a failed payment, and retries it or puts the lease in default.
   */
  protected function failed(LeaseInterface $lease, string $error): void {
    $settings = $this->configFactory->get('commerce_domains_lease.settings');
    $failures = $lease->getFailures() + 1;
    $lease->set('failures', $failures)->set('last_error', $error);
    $this->logger->warning('A payment of lease @lease (@domain) failed: @error', [
      '@lease' => $lease->id(),
      '@domain' => $lease->getDomain(),
      '@error' => $error,
    ]);
    if ($failures > (int) $settings->get('max_retries')) {
      $lease->set('state', LeaseInterface::DEFAULTED)->set('next_due', NULL)->save();
      $this->mail('defaulted', $lease, owner: TRUE);
      return;
    }
    $retry = $this->time->getRequestTime() + max(1, (int) $settings->get('retry_days')) * 86400;
    $lease->set('state', LeaseInterface::OVERDUE)->set('next_due', $retry)->save();
    $this->mail('failed', $lease, ['retry' => $retry], owner: TRUE);
  }

  /**
   * Marks a lease paid in full.
   */
  protected function complete(LeaseInterface $lease): void {
    $lease->set('state', LeaseInterface::COMPLETED)->set('next_due', NULL)->save();
    $this->mail('completed', $lease, owner: TRUE);
  }

  /**
   * Mails the buyer about a lease, and the owner when asked.
   */
  protected function mail(string $key, LeaseInterface $lease, array $params = [], bool $owner = FALSE): void {
    $params += ['lease' => $lease];
    $buyer = $lease->getOrder()?->getEmail() ?: $lease->getOwner()->getEmail();
    $langcode = $lease->getOwner()->getPreferredLangcode();
    if ($buyer) {
      $this->mailManager->mail('commerce_domains_lease', $key, $buyer, $langcode, $params + ['to' => 'buyer']);
    }
    $owner_mail = (string) $this->configFactory->get('commerce_domains_lease.settings')->get('notify_mail')
      ?: (string) $this->configFactory->get('system.site')->get('mail');
    if ($owner && $owner_mail) {
      $this->mailManager->mail('commerce_domains_lease', $key, $owner_mail, $langcode, $params + ['to' => 'owner']);
    }
  }

  /**
   * Returns a time some months later, on the same day where there is one.
   */
  public static function addMonths(int $time, int $months): int {
    $date = (new \DateTimeImmutable())->setTimestamp($time);
    $day = (int) $date->format('j');
    $target = $date->modify('first day of +' . $months . ' month');
    $day = min($day, (int) $target->format('t'));
    return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)->getTimestamp();
  }

}

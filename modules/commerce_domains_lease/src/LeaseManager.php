<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\Plugin\AdvancedQueue\JobType\LeaseChargeJob;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
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
 *
 * A payment whose outcome is unclear, as after a timeout, is never retried: the
 * money may have been taken. The lease stops and the owner is told to check at
 * the gateway, and settles it on the lease page.
 */
class LeaseManager {

  // The queue of lease payments.
  public const QUEUE = 'commerce_domains_lease';

  // How long a charge can hold its lock, longer than a gateway call takes.
  protected const CHARGE_LOCK_SECONDS = 180.0;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LeaseCalculator $calculator,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected LockBackendInterface $lock,
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
   * Only one charge of a lease runs at a time, and a lease is reloaded once it
   * is this one's turn, so a queue job and the Charge button can't both take
   * the same payment.
   *
   * @param \Drupal\commerce_domains_lease\Entity\LeaseInterface $lease
   *   The lease.
   * @param int|null $payments_made
   *   How many payments the caller saw made, by default the number on $lease.
   *   If that has changed by the time this runs, the payment the caller meant
   *   has been taken, and nothing is charged.
   *
   * @return bool
   *   Whether it was paid.
   */
  public function charge(LeaseInterface $lease, ?int $payments_made = NULL): bool {
    $payments_made ??= $lease->getPaymentsMade();
    $lock = $this->lockName($lease);
    if (!$this->lock->acquire($lock, self::CHARGE_LOCK_SECONDS)) {
      return FALSE;
    }
    try {
      $fresh = $this->entityTypeManager->getStorage('commerce_domains_lease')->loadUnchanged($lease->id());
      if (!$fresh instanceof LeaseInterface || $fresh->getPaymentsMade() !== $payments_made) {
        return FALSE;
      }
      return $this->chargeLocked($fresh);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Charges the next payment of a lease, holding its lock.
   */
  protected function chargeLocked(LeaseInterface $lease): bool {
    if (!$lease->isOpen() && $lease->getState() !== LeaseInterface::DEFAULTED) {
      return FALSE;
    }
    if ($this->stopIfOrderCanceled($lease)) {
      return FALSE;
    }
    if ($lease->isManual()) {
      $this->request($lease);
      return FALSE;
    }
    // An earlier charge never reported back. Charging again could take the
    // money twice, so it is settled first.
    if ($lease->getChargeStarted() !== NULL) {
      return $this->recoverCharge($lease);
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
    // Saved before the gateway is called: if this process dies between the
    // charge and recording it, the next try finds this instead of charging.
    $lease->set('charge_started', $this->time->getRequestTime())->save();
    try {
      $plugin->createPayment($payment, TRUE);
    }
    // The gateway said no, so no money moved and it is safe to try again.
    catch (DeclineException | InvalidRequestException $e) {
      $lease->set('charge_started', NULL);
      $this->failed($lease, $e->getMessage());
      return FALSE;
    }
    // Anything else, such as a timeout, says nothing about the money.
    catch (\Throwable $e) {
      if (!$payment->isNew() && $payment->getState()->getId() === 'completed') {
        $this->logger->warning('Lease @lease (@domain): the gateway reported an error after the payment was recorded, so it counts as paid: @error', [
          '@lease' => $lease->id(),
          '@domain' => $lease->getDomain(),
          '@error' => $e->getMessage(),
        ]);
        $this->recordCharge($lease, $amount);
        return TRUE;
      }
      $this->hold($lease, $e->getMessage());
      return FALSE;
    }

    $this->recordCharge($lease, $amount);
    return TRUE;
  }

  /**
   * Counts a charge on the lease.
   */
  protected function recordCharge(LeaseInterface $lease, Price $amount): void {
    $paid = $lease->getPaid()->add($amount);
    $lease->set('paid', $paid)
      ->set('payments_made', $lease->getPaymentsMade() + 1)
      ->set('failures', 0)
      ->set('last_error', NULL)
      ->set('charge_started', NULL)
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
  }

  /**
   * Settles a charge that never reported back, without charging again.
   *
   * A payment on the order that the lease hasn't counted is that charge: the
   * money arrived and only the lease's record of it was lost. Otherwise nobody
   * here can know, and the owner has to look at the gateway.
   *
   * @return bool
   *   Whether it turned out to be paid.
   */
  protected function recoverCharge(LeaseInterface $lease): bool {
    $amount = $lease->getNextAmount();
    if ($this->hasUncountedPayment($lease, $amount)) {
      $this->recordCharge($lease, $amount);
      return TRUE;
    }
    $this->hold($lease, 'An earlier charge did not finish.');
    return FALSE;
  }

  /**
   * Whether the lease's order has a payment of the amount the lease hasn't got.
   */
  protected function hasUncountedPayment(LeaseInterface $lease, Price $amount): bool {
    $order = $lease->getOrder();
    $on_order = $order ? $this->paidOn($order) : NULL;
    return $on_order && $on_order->subtract($lease->getPaid())->greaterThanOrEqual($amount);
  }

  /**
   * Stops charging a lease until the owner has checked an unclear charge.
   */
  protected function hold(LeaseInterface $lease, string $error): void {
    $this->logger->error('Lease @lease (@domain): it is not known whether a charge of @amount went through, so nothing more is charged until it is checked: @error', [
      '@lease' => $lease->id(),
      '@domain' => $lease->getDomain(),
      '@amount' => (string) $lease->getNextAmount(),
      '@error' => $error,
    ]);
    $lease->set('state', LeaseInterface::OVERDUE)
      ->set('next_due', NULL)
      ->set('last_error', $error)
      ->save();
    $this->mail('unconfirmed', $lease, owner: TRUE, buyer: FALSE);
  }

  /**
   * Settles an unclear charge, once the owner has checked at the gateway.
   *
   * @param \Drupal\commerce_domains_lease\Entity\LeaseInterface $lease
   *   The lease.
   * @param bool $taken
   *   TRUE if the money was taken, which records the payment. FALSE if it
   *   wasn't, which lets the next run charge it again.
   */
  public function settleCharge(LeaseInterface $lease, bool $taken): void {
    $lock = $this->lockName($lease);
    if (!$this->lock->acquire($lock, self::CHARGE_LOCK_SECONDS)) {
      return;
    }
    try {
      $lease = $this->entityTypeManager->getStorage('commerce_domains_lease')->loadUnchanged($lease->id());
      if (!$lease instanceof LeaseInterface || $lease->getChargeStarted() === NULL) {
        return;
      }
      if (!$taken) {
        $lease->set('charge_started', NULL)
          ->set('last_error', NULL)
          ->set('next_due', $this->time->getRequestTime())
          ->save();
        return;
      }
      $amount = $lease->getNextAmount();
      if (!$this->hasUncountedPayment($lease, $amount)) {
        $this->entityTypeManager->getStorage('commerce_payment')->create([
          'state' => 'completed',
          'amount' => $amount,
          'payment_gateway' => $lease->getPaymentMethod()?->getPaymentGatewayId() ?? $lease->getPaymentGateway()?->id(),
          'payment_method' => $lease->getPaymentMethod()?->id(),
          'order_id' => $lease->getOrder()?->id(),
        ])->save();
      }
      $this->recordCharge($lease, $amount);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Cancels a lease whose order was canceled.
   *
   * @return bool
   *   Whether the order was canceled.
   */
  protected function stopIfOrderCanceled(LeaseInterface $lease): bool {
    if ($lease->getOrder()?->getState()->getId() !== 'canceled') {
      return FALSE;
    }
    $this->cancel($lease);
    return TRUE;
  }

  /**
   * Returns the name of the lock on charging a lease.
   */
  protected function lockName(LeaseInterface $lease): string {
    return self::QUEUE . ':charge:' . $lease->id();
  }

  /**
   * Asks the buyer of a manual lease for the payment due.
   *
   * The first time it is due the buyer gets the amount and how to pay it.
   * Unpaid after the retry days, it counts as a missed payment: the buyer is
   * reminded, and past the tries the lease is in default, as with a card.
   */
  public function request(LeaseInterface $lease): void {
    if (!$lease->isOpen() || !$lease->getRemaining()->isPositive() || $this->stopIfOrderCanceled($lease)) {
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
    assert($payment instanceof PaymentInterface);
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
  protected function mail(string $key, LeaseInterface $lease, array $params = [], bool $owner = FALSE, bool $buyer = TRUE): void {
    $params += ['lease' => $lease];
    $buyer_mail = $lease->getOrder()?->getEmail() ?: $lease->getOwner()->getEmail();
    $langcode = $lease->getOwner()->getPreferredLangcode();
    if ($buyer && $buyer_mail) {
      $this->mailManager->mail('commerce_domains_lease', $key, $buyer_mail, $langcode, $params + ['to' => 'buyer']);
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

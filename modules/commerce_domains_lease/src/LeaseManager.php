<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\Plugin\AdvancedQueue\JobType\LeaseChargeJob;
use Drupal\commerce_order\Entity\OrderInterface;
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
    $placed = $order->getPlacedTime() ?: $this->time->getRequestTime();
    /** @var \Drupal\commerce_domains_lease\Entity\LeaseInterface $lease */
    $lease = $this->entityTypeManager->getStorage('commerce_domains_lease')->create([
      'order_id' => $order->id(),
      'uid' => $order->getCustomerId(),
      'domain' => $product && !$product->get('domain')->isEmpty() ? $product->get('domain')->value : (string) $item?->getTitle(),
      'payment_method' => $method instanceof PaymentMethodInterface ? $method->id() : NULL,
      'months' => $plan->months,
      'total' => $plan->total,
      'down_payment' => $plan->downPayment,
      'installment' => $plan->installment,
      'paid' => $order->getTotalPaid() ?? new Price('0', $plan->total->getCurrencyCode()),
      'next_due' => self::addMonths($placed, 1),
    ]);
    $lease->save();
    if (!$method instanceof PaymentMethodInterface || !$method->isReusable()) {
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
   * Charges the next payment of a lease.
   *
   * @return bool
   *   Whether it was paid.
   */
  public function charge(LeaseInterface $lease): bool {
    if (!$lease->isOpen() && $lease->getState() !== LeaseInterface::DEFAULTED) {
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

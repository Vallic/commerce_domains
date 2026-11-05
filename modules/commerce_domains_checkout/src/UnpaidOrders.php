<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout;

use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Releases domains held by orders nobody paid for.
 *
 * Placing an order takes its domain off sale, because a bank transfer takes
 * days and the domain mustn't sell twice meanwhile. Left at that, anyone could
 * take a domain off sale for free, so the hold has a limit: the buyer is
 * reminded two days before it ends, and then the order is canceled, which puts
 * the domain back on sale.
 *
 * Only orders where nothing can be on its way are touched: nothing paid, no
 * payment beyond a pending one, and not paid through an escrow service. Orders
 * placed before this first ran are left alone, as nobody told those buyers.
 */
class UnpaidOrders {

  // State: only orders placed from this time on have a limited hold.
  public const STATE_SINCE = 'commerce_domains_checkout.unpaid_hold_since';

  // Order data: when the buyer was reminded.
  public const DATA_REMINDED = 'commerce_domains_checkout_unpaid_reminded';

  // How long before the end of the hold the buyer is reminded.
  protected const REMINDER_DAYS = 2;

  // How many orders one run looks at.
  protected const BATCH = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected MailManagerInterface $mailManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns how many days an unpaid order holds its domain; 0 for no limit.
   */
  public function holdDays(): int {
    return max(0, (int) $this->configFactory->get('commerce_domains_checkout.settings')->get('unpaid_hold_days'));
  }

  /**
   * Returns when an order's hold on its domain ends.
   */
  public function holdEnds(OrderInterface $order): int {
    return (int) $order->getPlacedTime() + $this->holdDays() * 86400;
  }

  /**
   * Reminds the buyers whose hold is about to end, and ends those past it.
   *
   * @return int
   *   How many orders were canceled.
   */
  public function process(): int {
    $days = $this->holdDays();
    if ($days < 1 || !$this->entityTypeManager->hasDefinition('commerce_payment')) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $since = $this->state->get(self::STATE_SINCE);
    if ($since === NULL) {
      $this->state->set(self::STATE_SINCE, $now);
      return 0;
    }

    /** @var \Drupal\commerce_order\OrderStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('placed', (int) $since, '>=')
      ->condition('placed', $now - max(0, $days - self::REMINDER_DAYS) * 86400, '<=')
      ->condition('state', ['draft', 'canceled', 'completed'], 'NOT IN')
      ->sort('placed')
      ->range(0, self::BATCH)
      ->execute();

    $canceled = 0;
    foreach ($ids as $id) {
      $id = (int) $id;
      // A payment being recorded holds the same lock, so one arriving now is
      // either seen below or finds the order canceled.
      try {
        $order = $storage->loadForUpdate($id);
        if ($order && $this->isHolding($order)) {
          if ($this->holdEnds($order) <= $now) {
            $this->cancel($order);
            $canceled++;
          }
          elseif (!$order->getData(self::DATA_REMINDED)) {
            $this->remind($order);
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Releasing the domain of unpaid order @order failed: @error', [
          '@order' => $id,
          '@error' => $e->getMessage(),
        ]);
      }
      finally {
        $storage->releaseLock($id);
      }
    }
    return $canceled;
  }

  /**
   * Whether an order is holding a domain with nothing paid or on its way.
   */
  public function isHolding(OrderInterface $order): bool {
    if ($order->isPaid() || $order->getTotalPaid()?->isPositive() || !$this->domainNames($order)) {
      return FALSE;
    }
    if (!isset($order->getState()->getTransitions()['cancel'])) {
      return FALSE;
    }
    // Set at checkout: an order made by hand is the owner's to deal with.
    $gateway = $order->get('payment_gateway')->entity;
    if (!$gateway instanceof PaymentGatewayInterface || $this->isEscrow($gateway)) {
      return FALSE;
    }
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payments */
    $payments = $this->entityTypeManager->getStorage('commerce_payment');
    foreach ($payments->loadMultipleByOrder($order) as $payment) {
      // Anything past pending may be money: an authorization, or a refund.
      if ($payment->getState()->getId() !== 'pending') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Tells the owner about money that arrived for a canceled order.
   *
   * The domain may have sold again by now, so this isn't for code to settle.
   */
  public function latePayment(PaymentInterface $payment): void {
    $order = $payment->getOrder();
    if (!$order || $order->getState()->getId() !== 'canceled' || $payment->getState()->getId() !== 'completed') {
      return;
    }
    $original = $payment->getOriginal();
    if ($original instanceof PaymentInterface && $original->getState()->getId() === 'completed') {
      return;
    }
    $this->logger->error('A payment of @amount arrived for canceled order @order (@domain). Its domain may have sold again: refund it, or sell it to this buyer by hand.', [
      '@amount' => (string) $payment->getAmount(),
      '@order' => $order->getOrderNumber() ?: $order->id(),
      '@domain' => implode(', ', $this->domainNames($order)),
    ]);
    $this->mailOwner('unpaid_late_payment', $order, ['amount' => $payment->getAmount()]);
  }

  /**
   * Returns the names of an order's domains.
   *
   * @return string[]
   *   The names, empty when it has none.
   */
  public function domainNames(OrderInterface $order): array {
    $names = [];
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
      if ($product && $product->hasField('domain')) {
        $names[] = (string) ($product->get('domain')->value ?: $product->label());
      }
    }
    return $names;
  }

  /**
   * Reminds the buyer that the hold is about to end.
   */
  protected function remind(OrderInterface $order): void {
    $order->setData(self::DATA_REMINDED, $this->time->getRequestTime())->save();
    $this->mailBuyer('unpaid_reminder', $order);
  }

  /**
   * Cancels an order past its hold, which puts its domain back on sale.
   */
  protected function cancel(OrderInterface $order): void {
    $order->getState()->applyTransitionById('cancel');
    $order->save();
    $this->logger->notice('Canceled order @order, unpaid after @days days: @domain goes back on sale.', [
      '@order' => $order->getOrderNumber() ?: $order->id(),
      '@days' => $this->holdDays(),
      '@domain' => implode(', ', $this->domainNames($order)),
    ]);
    $this->mailBuyer('unpaid_canceled', $order);
    $this->mailOwner('unpaid_canceled', $order);
  }

  /**
   * Whether a gateway pays through an escrow service, in its own time.
   */
  protected function isEscrow(PaymentGatewayInterface $gateway): bool {
    try {
      return $gateway->getPlugin() instanceof EscrowGatewayInterface;
    }
    catch (PluginException) {
      return FALSE;
    }
  }

  /**
   * Mails the buyer about an order.
   */
  protected function mailBuyer(string $key, OrderInterface $order): void {
    if ($mail = $order->getEmail()) {
      $this->mailManager->mail('commerce_domains_checkout', $key, $mail, $order->getCustomer()->getPreferredLangcode(), [
        'order' => $order,
        'to' => 'buyer',
      ]);
    }
  }

  /**
   * Mails the owner about an order.
   *
   * To where Commerce Domains Owner sends the owner's mail, if it is installed
   * and has an address, otherwise the store's, otherwise the site's.
   */
  protected function mailOwner(string $key, OrderInterface $order, array $params = []): void {
    $mail = (string) $this->configFactory->get('commerce_domains_owner.settings')->get('notify_mail')
      ?: (string) $order->getStore()?->getEmail()
      ?: (string) $this->configFactory->get('system.site')->get('mail');
    if ($mail !== '') {
      $this->mailManager->mail('commerce_domains_checkout', $key, $mail, $order->getCustomer()->getPreferredLangcode(), $params + [
        'order' => $order,
        'to' => 'owner',
      ]);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Moves handovers through their workflow.
 *
 * Each step goes to the registrar where its API can take it, emails the
 * buyer where they have something to do or to know, and is added to the
 * order's activity when Commerce Log is on. Registrar calls are also logged,
 * with who made them, as on the domain's Registrar tab.
 *
 * Auth codes pass through sendAuthCode() and HandoverMail only, into the
 * buyer's email: never into a field, log, message or order data, and only
 * in parameters marked sensitive, which stack traces leave out.
 */
class HandoverManager {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HandoverMail $mail,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the order's items that sell a domain.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   The items, keyed by order item ID.
   */
  public function domainItems(OrderInterface $order): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      if ($this->product($item)) {
        $items[$item->id()] = $item;
      }
    }
    return $items;
  }

  /**
   * Returns an order's handovers.
   *
   * @return \Drupal\commerce_domains_handover\Entity\HandoverInterface[]
   *   The handovers, oldest first, keyed by ID.
   */
  public function forOrder(OrderInterface $order): array {
    if ($order->isNew()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_domains_handover');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order->id())
      ->sort('handover_id')
      ->execute();
    /** @var \Drupal\commerce_domains_handover\Entity\HandoverInterface[] $handovers */
    $handovers = $ids ? $storage->loadMultiple($ids) : [];
    return $handovers;
  }

  /**
   * Whether the order is paid through Escrow.com, which runs its handover.
   *
   * Escrow.com holds the money until the buyer confirms they have the
   * domain, so Commerce counts such an order paid only after the handover.
   * Neither class needs to exist for instanceof, so commerce_escrow and
   * commerce_payment stay optional.
   */
  public function isEscrow(OrderInterface $order): bool {
    $gateway = $order->hasField('payment_gateway') ? $order->get('payment_gateway')->entity : NULL;
    if (!$gateway instanceof PaymentGatewayInterface) {
      return FALSE;
    }
    try {
      return $gateway->getPlugin() instanceof EscrowGatewayInterface;
    }
    catch (PluginException) {
      // A gateway whose module is gone pays nothing now.
      return FALSE;
    }
  }

  /**
   * Starts the handovers of an order placed and paid in full.
   *
   * Placing already took the domains off sale, so waiting for the money
   * costs nothing, while an auth code or a push can't be taken back. Orders
   * paid offline start when the seller records the payment. Escrow.com
   * orders are left to Escrow.com.
   *
   * @return \Drupal\commerce_domains_handover\Entity\HandoverInterface[]
   *   The handovers started.
   */
  public function startPaid(OrderInterface $order): array {
    if (!$this->isPlaced($order) || !$order->isPaid() || $this->isEscrow($order)) {
      return [];
    }
    return $this->start($order);
  }

  /**
   * Whether the order is placed and not canceled.
   */
  public function isPlaced(OrderInterface $order): bool {
    return !in_array($order->getState()->getId(), ['draft', 'canceled'], TRUE);
  }

  /**
   * Starts a handover for each domain of the order that has none yet.
   *
   * The buyer is emailed what happens next, for each domain.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param bool $manual
   *   Whether the seller starts them, rather than the payment.
   *
   * @return \Drupal\commerce_domains_handover\Entity\HandoverInterface[]
   *   The handovers started.
   */
  public function start(OrderInterface $order, bool $manual = FALSE): array {
    $started = [];
    $storage = $this->entityTypeManager->getStorage('commerce_domains_handover');
    foreach (array_diff_key($this->domainItems($order), $this->handedOverItems($order)) as $item) {
      $product = $this->product($item);
      /** @var \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover */
      $handover = $storage->create([
        'order_id' => $order->id(),
        'order_item_id' => $item->id(),
        'product_id' => $product->id(),
        'domain' => (string) $product->get('domain')->value ?: DomainName::normalize($product->getTitle()),
      ]);
      $handover->save();
      $this->log($handover, 'start', ['manual' => $manual]);
      $this->mail->send($handover, HandoverMail::STARTED);
      $started[] = $handover;
    }
    return $started;
  }

  /**
   * Returns the order items of an order that have a handover.
   *
   * @return array<int|string, true>
   *   Flags keyed by order item ID.
   */
  public function handedOverItems(OrderInterface $order): array {
    $items = [];
    foreach ($this->forOrder($order) as $handover) {
      $items[$handover->get('order_item_id')->target_id] = TRUE;
    }
    return $items;
  }

  /**
   * Whether a step can be taken now.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param string $transition
   *   The workflow transition: unlock, send_code, push, complete or cancel.
   */
  public function allows(HandoverInterface $handover, string $transition): bool {
    if (!$handover->getState()->isTransitionAllowed($transition)) {
      return FALSE;
    }
    // Only a registrar API can unlock here; otherwise the seller unlocks in
    // the account and goes on to the auth code.
    return $transition !== 'unlock' || $this->management($handover) !== NULL;
  }

  /**
   * Returns the API that manages the domain at its registrar, if any.
   */
  public function management(HandoverInterface $handover): ?DomainManagementInterface {
    $provider = $handover->getRegistrar();
    $plugin = $provider && $provider->status() ? $provider->getPlugin() : NULL;
    return $plugin instanceof DomainManagementInterface ? $plugin : NULL;
  }

  /**
   * Unlocks the domain at its registrar.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the registrar refuses; nothing changes then.
   * @throws \Drupal\commerce_domains_handover\HandoverException
   *   When the handover is past this step.
   */
  public function unlock(HandoverInterface $handover): void {
    $this->assertAllowed($handover, 'unlock');
    // Allowed only with an API, see allows().
    $plugin = $this->management($handover);
    assert($plugin instanceof DomainManagementInterface);
    $this->atRegistrar($handover, 'transfer lock off', fn() => $plugin->setTransferLock($handover->getDomain(), FALSE));
    $this->apply($handover, 'unlock');
  }

  /**
   * Emails the buyer the auth code.
   *
   * The code comes from the registrar's API, or from the seller who got it
   * in the account, for registrars whose API can't give it.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param string $code
   *   The code the seller got from the registrar, or '' to ask the API.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the registrar refuses; nothing changes then.
   * @throws \Drupal\commerce_domains_handover\HandoverException
   *   When there is no code to send, or the email could not be sent.
   */
  public function sendAuthCode(HandoverInterface $handover, #[\SensitiveParameter] string $code = ''): void {
    $this->assertAllowed($handover, 'send_code');
    $code = trim($code);
    $entered = $code !== '';
    $args = [
      '@domain' => $handover->getDomain(),
      '@registrar' => (string) $handover->getRegistrar()?->label(),
      '@mail' => (string) $handover->getOrder()?->getEmail(),
    ];
    if (!$entered) {
      $plugin = $this->management($handover);
      if (!$plugin) {
        $reason = $this->t('Enter the auth code for @domain: there is no registrar API to get it from.', $args);
        throw new HandoverException($reason);
      }
      $code = $this->atRegistrar($handover, 'transfer auth code requested', fn() => $plugin->getAuthCode($handover->getDomain()));
      if ($code === '') {
        $reason = $this->t("@registrar emailed the auth code for @domain to the domain's administrative contact. Enter it here to send it to the buyer.", $args);
        throw new HandoverException($reason);
      }
    }
    if (!$this->mail->send($handover, HandoverMail::AUTH_CODE, $code)) {
      $reason = $this->t('The email to @mail could not be sent, so the buyer has no auth code. Try again.', $args);
      throw new HandoverException($reason);
    }
    $this->logger->notice('@domain: auth code emailed to the buyer of order @order.', $this->context($handover));
    $this->apply($handover, 'send_code', ['entered' => $entered]);
  }

  /**
   * Marks the domain pushed to the buyer's account, and tells the buyer.
   *
   * Registrars have no API for pushes between accounts, so the seller pushes
   * in the account first.
   *
   * @return bool
   *   Whether the buyer was emailed.
   *
   * @throws \Drupal\commerce_domains_handover\HandoverException
   *   When the handover is past this step.
   */
  public function push(HandoverInterface $handover): bool {
    $this->assertAllowed($handover, 'push');
    $this->apply($handover, 'push');
    return $this->mail->send($handover, HandoverMail::PUSHED);
  }

  /**
   * Marks the domain transferred, and tells the buyer.
   *
   * The domain was taken off sale when its order was placed, and stays in
   * the seller's account until it is transferred: registrar syncs must keep
   * expecting it there until now. From now on the product no longer names
   * the seller's registrar, the catalog's record of where the domain is, so
   * syncs stop reporting it missing from that account. The handover keeps
   * the registrar for the record. A sync that still finds the domain in the
   * account records it there again, as it does for any domain it finds.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param int|null $time
   *   When the domain reached the buyer, as a Unix time; now by default.
   *
   * @return bool
   *   Whether the buyer was emailed.
   *
   * @throws \Drupal\commerce_domains_handover\HandoverException
   *   When the handover is past this step.
   */
  public function complete(HandoverInterface $handover, ?int $time = NULL): bool {
    $this->assertAllowed($handover, 'complete');
    $handover->setTransferredTime($time ?? $this->time->getRequestTime());
    $this->apply($handover, 'complete');
    $this->release($handover);
    return $this->mail->send($handover, HandoverMail::TRANSFERRED);
  }

  /**
   * Cancels a handover. The buyer is not emailed.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param bool $with_order
   *   Whether its order was canceled.
   *
   * @throws \Drupal\commerce_domains_handover\HandoverException
   *   When the handover is closed.
   */
  public function cancel(HandoverInterface $handover, bool $with_order = FALSE): void {
    $this->assertAllowed($handover, 'cancel');
    $this->apply($handover, 'cancel', ['with_order' => $with_order]);
  }

  /**
   * Cancels the open handovers of a canceled order.
   */
  public function orderCanceled(OrderInterface $order): void {
    foreach ($this->forOrder($order) as $handover) {
      if ($this->allows($handover, 'cancel')) {
        $this->cancel($handover, TRUE);
      }
    }
  }

  /**
   * Deletes the handovers of a deleted order.
   */
  public function orderDeleted(OrderInterface $order): void {
    if ($handovers = $this->forOrder($order)) {
      $this->entityTypeManager->getStorage('commerce_domains_handover')->delete($handovers);
    }
  }

  /**
   * Takes the seller's registrar off a transferred domain's product.
   */
  protected function release(HandoverInterface $handover): void {
    $product = $handover->getProduct();
    $registrar = $handover->getRegistrar();
    if (!$product || !$registrar || !$product->hasField('domain_registrar') || $product->get('domain_registrar')->target_id !== $registrar->id()) {
      return;
    }
    $product->set('domain_registrar', NULL);
    // Where a domain is registered isn't an edit, as for registrar syncs:
    // the product keeps its changed time.
    $product->setSyncing(TRUE);
    $product->save();
    $product->setSyncing(FALSE);
    $this->logger->notice('@domain: transferred to the buyer of order @order, no longer expected at @provider.', $this->context($handover));
  }

  /**
   * Runs one call at the registrar and logs it, as the Registrar tab does.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param string $change
   *   What the call does, for the log.
   * @param callable $call
   *   The call. What it returns is passed back, never logged.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the registrar refuses.
   */
  protected function atRegistrar(HandoverInterface $handover, string $change, callable $call): mixed {
    $context = $this->context($handover) + ['@change' => $change];
    try {
      $result = $call();
    }
    catch (ProviderException $e) {
      $this->logger->warning('@domain at @provider: @change failed, for the handover of order @order: @error', $context + ['@error' => $e->getMessage()]);
      throw $e;
    }
    $this->logger->notice('@domain at @provider: @change, for the handover of order @order.', $context);
    return $result;
  }

  /**
   * Applies a transition, saves the handover and logs the step.
   */
  protected function apply(HandoverInterface $handover, string $transition, array $params = []): void {
    $handover->getState()->applyTransitionById($transition);
    $handover->save();
    $this->log($handover, $transition, $params);
  }

  /**
   * Throws unless a step can be taken now.
   *
   * @throws \Drupal\commerce_domains_handover\HandoverException
   */
  protected function assertAllowed(HandoverInterface $handover, string $transition): void {
    if (!$this->allows($handover, $transition)) {
      $reason = $this->t('The handover of @domain is at "@state", where that step is not open.', [
        '@domain' => $handover->getDomain(),
        '@state' => $handover->getState()->getLabel(),
      ]);
      throw new HandoverException($reason);
    }
  }

  /**
   * Adds a step to the order's activity, when Commerce Log is on.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param string $step
   *   The log template, after commerce_domains_handover_.
   * @param array $params
   *   Flags for the template. Never a code.
   */
  protected function log(HandoverInterface $handover, string $step, array $params = []): void {
    $order = $handover->getOrder();
    if (!$order || !$this->entityTypeManager->hasDefinition('commerce_log')) {
      return;
    }
    /** @var \Drupal\commerce_log\LogStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_log');
    $storage->generate($order, 'commerce_domains_handover_' . $step, $params + [
      'domain' => $handover->getDomain(),
      'registrar' => (string) $handover->getRegistrar()?->label(),
    ])->save();
  }

  /**
   * Returns the placeholders for a log message about a handover.
   */
  protected function context(HandoverInterface $handover): array {
    $order = $handover->getOrder();
    return [
      '@domain' => $handover->getDomain(),
      '@provider' => (string) $handover->getRegistrar()?->label(),
      '@order' => $order ? ($order->getOrderNumber() ?: $order->id()) : '',
    ];
  }

  /**
   * Returns the domain product an order item sells, if any.
   */
  protected function product(OrderItemInterface $item): ?ProductInterface {
    $variation = $item->getPurchasedEntity();
    $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
    return $product && $product->hasField('domain') ? $product : NULL;
  }

}

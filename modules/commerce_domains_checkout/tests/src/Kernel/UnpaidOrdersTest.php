<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_checkout\Kernel;

use Drupal\commerce_domains_checkout\EventSubscriber\LatePaymentSubscriber;
use Drupal\commerce_domains_checkout\Hook\UnpaidOrderHooks;
use Drupal\commerce_domains_checkout\UnpaidOrders;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an unpaid order only holds its domain for a while.
 */
#[CoversClass(UnpaidOrders::class)]
#[CoversClass(UnpaidOrderHooks::class)]
#[CoversClass(LatePaymentSubscriber::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class UnpaidOrdersTest extends DomainsKernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'address',
    'options',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_cart',
    'commerce_checkout',
    'commerce_payment',
    'commerce_domains_checkout',
  ];

  /**
   * The service.
   */
  protected UnpaidOrders $unpaidOrders;

  /**
   * The current time.
   */
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig([
      'system',
      'commerce_order',
      'commerce_cart',
      'commerce_checkout',
      'commerce_payment',
      'commerce_domains_checkout',
    ]);
    // A workflow in which a placed order can be canceled.
    OrderType::load('default')->setWorkflowId('order_fulfillment')->save();
    PaymentGateway::create(['id' => 'bank', 'label' => 'Bank', 'plugin' => 'manual'])->save();
    $this->config('system.site')->set('mail', 'owner@example.com')->save();
    $this->unpaidOrders = $this->container->get(UnpaidOrders::class);
    $this->now = $this->container->get('datetime.time')->getRequestTime();
    // The hold started long before the orders here.
    $this->container->get('state')->set(UnpaidOrders::STATE_SINCE, $this->now - 100 * 86400);
  }

  /**
   * Places an order for a domain, some days ago.
   */
  protected function placedOrder(ProductInterface $product, int $days_ago, ?string $gateway = 'bank'): OrderInterface {
    $variations = $product->getVariations();
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $items */
    $items = $this->entityTypeManager->getStorage('commerce_order_item');
    $item = $items->createFromPurchasableEntity(reset($variations));
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => 0,
      'mail' => 'buyer@example.com',
      'state' => 'draft',
      'order_items' => [$item],
      'payment_gateway' => $gateway,
    ]);
    $order->save();
    $order->getState()->applyTransitionById('place');
    $order->setPlacedTime($this->now - $days_ago * 86400);
    $order->save();
    return $order;
  }

  /**
   * Adds a payment to an order.
   */
  protected function payment(OrderInterface $order, string $state, string $amount = '1000'): void {
    Payment::create([
      'state' => $state,
      'amount' => new Price($amount, 'USD'),
      'payment_gateway' => 'bank',
      'order_id' => $order->id(),
    ])->save();
    $this->container->get('commerce_payment.order_updater')->destruct();
  }

  /**
   * Whether a domain is on sale, read afresh.
   */
  protected function onSale(ProductInterface $product): bool {
    $this->entityTypeManager->getStorage('commerce_product')->resetCache();
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $fresh */
    $fresh = $this->entityTypeManager->getStorage('commerce_product')->load($product->id());
    $variations = $fresh->getVariations();
    $variation = reset($variations);
    $field = $variation->hasField('in_stock') ? 'in_stock' : 'status';
    return (bool) $variation->get($field)->value;
  }

  /**
   * Returns the state of an order, read afresh.
   */
  protected function state(OrderInterface $order): string {
    $this->entityTypeManager->getStorage('commerce_order')->resetCache();
    return Order::load($order->id())->getState()->getId();
  }

  /**
   * Tests an order from placing to the end of its hold.
   */
  public function testHoldEnds(): void {
    $product = $this->createDomain('Held.com', 1000);

    // Placed today, it is left alone.
    $order = $this->placedOrder($product, 0);
    $this->assertFalse($this->onSale($product), 'Placing takes it off sale.');
    $this->assertSame(0, $this->unpaidOrders->process());
    $this->assertSame([], $this->getMails(['key' => 'unpaid_reminder']));

    // Two days before the end, the buyer is reminded, once.
    $order->setPlacedTime($this->now - 5 * 86400)->save();
    $this->assertSame(0, $this->unpaidOrders->process());
    $this->assertSame(0, $this->unpaidOrders->process());
    $mails = $this->getMails(['key' => 'unpaid_reminder']);
    $this->assertCount(1, $mails);
    $this->assertSame('buyer@example.com', $mails[0]['to']);
    $this->assertSame('Your payment for held.com', (string) $mails[0]['subject']);
    $this->assertStringContainsString('$1,000.00', (string) $mails[0]['body']);
    $this->assertFalse($this->onSale($product));
    $this->assertSame('fulfillment', $this->state($order));

    // At the end, the order is canceled and the domain is back on sale.
    $order = Order::load($order->id());
    $order->setPlacedTime($this->now - 7 * 86400)->save();
    $this->assertSame(1, $this->unpaidOrders->process());
    $this->assertSame('canceled', $this->state($order));
    $this->assertTrue($this->onSale($product));
    $buyer = $this->getMails(['key' => 'unpaid_canceled', 'to' => 'buyer@example.com']);
    $this->assertSame('Your order for held.com was canceled', (string) $buyer[0]['subject']);
    $owner = $this->getMails(['key' => 'unpaid_canceled', 'to' => 'owner@example.com']);
    $this->assertSame('Unpaid order canceled: held.com', (string) $owner[0]['subject']);

    // Nothing more happens to it.
    $this->assertSame(0, $this->unpaidOrders->process());
    $this->assertCount(2, $this->getMails(['key' => 'unpaid_canceled']));
  }

  /**
   * Tests that a pending bank transfer doesn't stop the hold ending.
   */
  public function testPendingPaymentStillEnds(): void {
    $product = $this->createDomain('Pending.com', 1000);
    $order = $this->placedOrder($product, 8);
    $this->payment($order, 'pending');

    $this->assertSame(1, $this->unpaidOrders->process());

    $this->assertSame('canceled', $this->state($order));
    $this->assertTrue($this->onSale($product));
  }

  /**
   * Tests the orders whose domain must stay held.
   */
  #[DataProvider('heldProvider')]
  public function testHeldOrders(string $payment_state, string $amount): void {
    $product = $this->createDomain('Paid.com', 1000);
    $order = $this->placedOrder($product, 30);
    $this->payment($order, $payment_state, $amount);

    $this->assertSame(0, $this->unpaidOrders->process());

    $this->assertSame('fulfillment', $this->state($order));
    $this->assertFalse($this->onSale($product));
    $this->assertSame([], $this->getMails(['module' => 'commerce_domains_checkout']));
  }

  /**
   * Data for testHeldOrders().
   */
  public static function heldProvider(): array {
    return [
      'paid' => ['completed', '1000'],
      'part paid, as a lease' => ['completed', '100'],
      'authorized' => ['authorization', '1000'],
      'refunded' => ['refunded', '1000'],
      'being processed' => ['new', '1000'],
    ];
  }

  /**
   * Tests that orders placed before the hold started are left alone.
   */
  public function testOrdersBeforeHoldStarted(): void {
    $state = $this->container->get('state');
    $state->delete(UnpaidOrders::STATE_SINCE);
    $product = $this->createDomain('Old.com', 1000);
    $order = $this->placedOrder($product, 30);

    // The first run only notes when the hold starts.
    $this->assertSame(0, $this->unpaidOrders->process());
    $this->assertSame($this->now, $state->get(UnpaidOrders::STATE_SINCE));
    $this->assertSame(0, $this->unpaidOrders->process());

    $this->assertSame('fulfillment', $this->state($order));
    $this->assertFalse($this->onSale($product));
    $this->assertSame([], $this->getMails(['module' => 'commerce_domains_checkout']));
  }

  /**
   * Tests that the limit can be turned off.
   */
  public function testNoLimit(): void {
    $this->config('commerce_domains_checkout.settings')->set('unpaid_hold_days', 0)->save();
    $order = $this->placedOrder($this->createDomain('Forever.com', 1000), 30);

    $this->assertSame(0, $this->unpaidOrders->process());

    $this->assertSame('fulfillment', $this->state($order));
    $this->assertSame([], $this->getMails(['module' => 'commerce_domains_checkout']));
  }

  /**
   * Tests that an order made by hand, without a gateway, is left alone.
   */
  public function testOrderWithoutGateway(): void {
    $order = $this->placedOrder($this->createDomain('Byhand.com', 1000), 30, NULL);

    $this->assertSame(0, $this->unpaidOrders->process());

    $this->assertSame('fulfillment', $this->state($order));
  }

  /**
   * Tests that an order with nothing to pay isn't an unpaid one.
   */
  public function testFreeOrder(): void {
    $product = $this->createDomain('Free.com', 0);
    $order = $this->placedOrder($product, 30);

    $this->assertSame(0, $this->unpaidOrders->process());

    $this->assertSame('fulfillment', $this->state($order));
    $this->assertFalse($this->onSale($product));
  }

  /**
   * Tests that an order that can't be canceled is left alone.
   */
  public function testOrderThatCannotBeCanceled(): void {
    OrderType::load('default')->setWorkflowId('order_default')->save();
    $order = $this->placedOrder($this->createDomain('Done.com', 1000), 30);
    $this->assertSame('completed', $this->state($order));

    $this->assertSame(0, $this->unpaidOrders->process());

    $this->assertSame('completed', $this->state($order));
    $this->assertSame([], $this->getMails(['module' => 'commerce_domains_checkout']));
  }

  /**
   * Tests that the owner hears about money for a canceled order.
   */
  public function testLatePayment(): void {
    $product = $this->createDomain('Late.com', 1000);
    $order = $this->placedOrder($product, 8);
    $this->unpaidOrders->process();
    $this->assertSame('canceled', $this->state($order));

    // A pending payment isn't money yet.
    $this->payment($order, 'pending');
    $before = count($this->getMails(['key' => 'unpaid_late_payment']));
    $this->assertSame(0, $before);

    $payments = Payment::loadMultiple();
    $payment = reset($payments);
    $payment->setState('completed')->save();
    $mails = $this->getMails(['key' => 'unpaid_late_payment']);
    $this->assertCount(1, $mails);
    $this->assertSame('owner@example.com', $mails[0]['to']);
    $this->assertSame('Payment for a canceled order: late.com', (string) $mails[0]['subject']);
    $this->assertStringContainsString('$1,000.00', (string) $mails[0]['body']);

    // Saved again, it isn't news.
    $this->entityTypeManager->getStorage('commerce_payment')->resetCache();
    Payment::load($payment->id())->setRemoteState('changed')->save();
    $this->assertCount(1, $this->getMails(['key' => 'unpaid_late_payment']));
    $this->assertSame('canceled', $this->state($order));
  }

  /**
   * Tests what canceling an order puts back on sale.
   */
  public function testCancelingPutsBackOnSale(): void {
    // Canceled by hand, a placed unpaid order puts it back.
    $product = $this->createDomain('Byhand.com', 1000);
    $order = $this->placedOrder($product, 0);
    $this->assertFalse($this->onSale($product));
    $order->getState()->applyTransitionById('cancel');
    $order->save();
    $this->assertTrue($this->onSale($product));

    // With money on it, the domain may be on its way: it stays off sale.
    $paid = $this->createDomain('Paidfor.com', 1000);
    $order = $this->placedOrder($paid, 0);
    $this->payment($order, 'completed', '100');
    $order = Order::load($order->id());
    $this->assertTrue($order->getTotalPaid()->isPositive());
    $order->getState()->applyTransitionById('cancel');
    $order->save();
    $this->assertFalse($this->onSale($paid));

    // Canceling a draft never took it off sale, so it changes nothing. This
    // one was taken off sale for some other reason.
    $other = $this->createDomain('Other.com', 1000);
    $variations = $other->getVariations();
    $variation = reset($variations);
    $field = $variation->hasField('in_stock') ? 'in_stock' : 'status';
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $items */
    $items = $this->entityTypeManager->getStorage('commerce_order_item');
    $item = $items->createFromPurchasableEntity($variation);
    $item->save();
    $draft = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => 0,
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    $draft->save();
    $variation->set($field, FALSE)->save();
    $draft->getState()->applyTransitionById('cancel');
    $draft->save();
    $this->assertFalse($this->onSale($other));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce_domains_handover\EventSubscriber\OrderCompletionSubscriber;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests completing a paid order once its domains have been handed over.
 */
#[CoversClass(OrderCompletionSubscriber::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class OrderCompletionTest extends HandoverKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_escrow',
    'commerce_domains_offer_escrow_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['commerce_domains_handover']);
    OrderType::load('default')->setWorkflowId('order_fulfillment')->save();
    $this->config('commerce_domains_handover.settings')->set('complete_orders', TRUE)->save();
  }

  /**
   * Returns the order's state, reloaded.
   */
  protected function orderState(OrderInterface $order): string {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $storage->resetCache([$order->id()]);
    $reloaded = $storage->load($order->id());
    assert($reloaded instanceof OrderInterface);
    return $reloaded->getState()->getId();
  }

  /**
   * Tests that it is off unless a site turns it on.
   */
  public function testOffByDefault(): void {
    $this->config('commerce_domains_handover.settings')->delete();
    $this->installConfig(['commerce_domains_handover']);
    $this->assertFalse($this->config('commerce_domains_handover.settings')->get('complete_orders'));

    $order = $this->pay($this->placeOrder('Example.com'));
    $this->manager->complete($this->handover($order));
    $this->assertSame('fulfillment', $this->orderState($order));
  }

  /**
   * Tests completing, on the workflows that keep orders open.
   */
  public function testCompletes(): void {
    $order = $this->pay($this->placeOrder('Example.com'));
    $this->manager->complete($this->handover($order));
    $this->assertSame('completed', $this->orderState($order));

    OrderType::load('default')->setWorkflowId('order_default_validation')->save();
    $order = $this->pay($this->placeOrder('Refuse.com'));
    $this->assertSame('validation', $this->orderState($order));
    $this->manager->complete($this->handover($order));
    $this->assertSame('completed', $this->orderState($order));
  }

  /**
   * Tests that an order handed over before it was paid stays open.
   */
  public function testUnpaid(): void {
    $order = $this->placeOrder('Example.com');
    $this->manager->start($order, TRUE);
    $this->manager->complete($this->handover($order));
    $this->assertSame('fulfillment', $this->orderState($order));
  }

  /**
   * Tests that a canceled handover alone completes nothing.
   */
  public function testCanceled(): void {
    $order = $this->pay($this->placeOrder('Example.com'));
    $this->manager->cancel($this->handover($order));
    $this->assertSame('fulfillment', $this->orderState($order));
  }

  /**
   * Tests that the order waits for its last domain.
   */
  public function testSeveralDomains(): void {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $items = [];
    foreach (['Example.com', 'Refuse.com', 'Mailed.com'] as $title) {
      $variations = $this->createDomain($title, 1000, ['domain_registrar' => 'dynadot'])->getVariations();
      $item = $storage->createFromPurchasableEntity(reset($variations));
      $item->save();
      $items[] = $item;
    }
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => 0,
      'mail' => self::BUYER,
      'state' => 'draft',
      'order_items' => $items,
    ]);
    $order->save();
    $order->getState()->applyTransitionById('place');
    $order->save();
    $this->pay($order);

    $handovers = array_values($this->manager->forOrder($order));
    $this->assertCount(3, $handovers);
    $this->manager->complete($handovers[0]);
    $this->assertSame('fulfillment', $this->orderState($order));
    $this->manager->cancel($handovers[1]);
    $this->assertSame('fulfillment', $this->orderState($order));
    // One transferred is enough, once none is open.
    $this->manager->complete($handovers[2]);
    $this->assertSame('completed', $this->orderState($order));
  }

  /**
   * Tests that an order paid through Escrow.com is left to Escrow.com.
   */
  public function testEscrow(): void {
    PaymentGateway::create([
      'id' => 'escrow',
      'label' => 'Escrow.com',
      'plugin' => 'test_escrow_offer',
      'status' => TRUE,
    ])->save();
    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', NULL, ['payment_gateway' => 'escrow']));
    $this->manager->start($order, TRUE);
    $this->manager->complete($this->handover($order));
    $this->assertSame('fulfillment', $this->orderState($order));
  }

}

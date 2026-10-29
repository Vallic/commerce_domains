<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider\TestManagedRegistrar;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;

/**
 * Base for handover tests: registrars, a buyer and orders to hand over.
 *
 * Dynadot is the test registrar that manages domains; Hover has no API. Auth
 * codes from the test registrar are "code-" plus the domain.
 */
abstract class HandoverKernelTestBase extends DomainsKernelTestBase {

  use AssertMailTrait;

  // The test registrar's account in State.
  protected const ACCOUNT = 'commerce_domains_test.managed';

  // The buyer's address.
  protected const BUYER = 'buyer@example.com';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'dblog',
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_cart',
    'commerce_checkout',
    'commerce_payment',
    'commerce_log',
    'commerce_domains_checkout',
    'commerce_domains_handover',
  ];

  /**
   * The handover manager.
   */
  protected HandoverManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_log');
    $this->installEntitySchema('commerce_domains_handover');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig(['system', 'commerce_order', 'commerce_checkout', 'commerce_payment', 'commerce_log']);
    // User 1 may do anything; the people in these tests may not.
    $this->createUser();

    Provider::create(['id' => 'dynadot', 'label' => 'Dynadot', 'plugin' => 'test_managed_registrar'])->save();
    Provider::create(['id' => 'hover', 'label' => 'Hover'])->save();
    $this->container->get('state')->set(self::ACCOUNT, [
      'example.com' => ['expires' => 1_820_000_000, 'locked' => TRUE],
      'refuse.com' => ['expires' => 1_820_000_000, 'locked' => TRUE],
      'mailed.com' => ['expires' => 1_820_000_000, 'locked' => TRUE, 'emails_code' => TRUE],
    ]);
    $this->manager = $this->container->get(HandoverManager::class);
  }

  /**
   * Creates a placed order for a domain, with the buyer's answer.
   *
   * @param string $title
   *   The domain.
   * @param string|null $registrar
   *   The provider ID of its registrar.
   * @param \Drupal\commerce_domains_checkout\Transfer|null $transfer
   *   Where the buyer wants it, or NULL when they weren't asked.
   * @param array $values
   *   Extra order values.
   */
  protected function placeOrder(string $title, ?string $registrar = 'dynadot', ?Transfer $transfer = NULL, array $values = []): OrderInterface {
    $product = $this->createDomain($title, 1000, ['domain_registrar' => $registrar]);
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $variations = $product->getVariations();
    $item = $storage->createFromPurchasableEntity(reset($variations));
    $item->save();
    // A guest's order: the current user is the seller.
    $order = Order::create($values + [
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => 0,
      'mail' => self::BUYER,
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    $transfer?->toOrder($order);
    $order->save();
    $order->getState()->applyTransitionById('place');
    $order->save();
    return $order;
  }

  /**
   * Records the order as paid in full, as a completed payment would.
   */
  protected function pay(OrderInterface $order): OrderInterface {
    $order->setTotalPaid($order->getTotalPrice() ?? new Price('0', 'USD'));
    $order->save();
    return $order;
  }

  /**
   * Returns the order's only handover, reloaded.
   */
  protected function handover(OrderInterface $order): HandoverInterface {
    $this->entityTypeManager->getStorage('commerce_domains_handover')->resetCache();
    $handovers = $this->manager->forOrder($order);
    $this->assertCount(1, $handovers);
    return reset($handovers);
  }

  /**
   * Returns a product, reloaded.
   */
  protected function reloadProduct(ProductInterface $product): ProductInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $storage->resetCache([$product->id()]);
    $product = $storage->load($product->id());
    assert($product instanceof ProductInterface);
    return $product;
  }

  /**
   * Returns the test registrar's calls for a domain.
   */
  protected function calls(string $name): array {
    return array_values(array_filter(TestManagedRegistrar::calls($this->container->get('state')), fn(array $call) => $call[1] === $name));
  }

  /**
   * Returns the order's activity: the log templates, oldest first.
   *
   * @return string[]
   *   Template IDs.
   */
  protected function activity(OrderInterface $order): array {
    /** @var \Drupal\commerce_log\LogStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_log');
    $logs = $storage->loadMultipleByEntity($order);
    return array_values(array_map(fn($log) => $log->getTemplateId(), $logs));
  }

  /**
   * Returns the handover emails sent, leaving out order receipts.
   */
  protected function handoverMails(?string $to = NULL): array {
    $mails = array_filter($this->getMails(), fn(array $mail) => str_starts_with($mail['id'], 'commerce_commerce_domains_handover_'));
    return array_values($to === NULL ? $mails : array_filter($mails, fn(array $mail) => $mail['to'] === $to));
  }

  /**
   * Returns the subjects of the handover emails sent to an address.
   */
  protected function subjects(string $to = self::BUYER): array {
    return array_map('strval', array_column($this->handoverMails($to), 'subject'));
  }

  /**
   * Returns an email's body as one line, for matching across wrapping.
   */
  protected function body(array $mail): string {
    return preg_replace('/\s+/', ' ', (string) $mail['body']);
  }

}

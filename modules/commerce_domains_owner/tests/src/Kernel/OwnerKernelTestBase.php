<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;

/**
 * Base for owner tests: the costs trait on the domain type, and orders.
 */
abstract class OwnerKernelTestBase extends DomainsKernelTestBase {

  use AssertMailTrait;

  // A day, in seconds.
  protected const DAY = 86400;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_domains_owner',
  ];

  /**
   * {@inheritdoc}
   *
   * Permissions are checked for every account, the first one too.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * The request time, as the services see it.
   *
   * @var int
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
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig(['system', 'commerce_order', 'commerce_domains_owner']);
    // What installing the module does: the costs trait on the domain type.
    $this->container->get('module_handler')->loadInclude('commerce_domains_owner', 'install');
    commerce_domains_owner_install(FALSE);
    $this->now = $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * Creates a provider.
   */
  protected function createProvider(string $id, ?string $plugin = 'test_registrar', bool $status = TRUE): ProviderInterface {
    $provider = Provider::create([
      'id' => $id,
      'label' => ucfirst($id),
      'plugin' => $plugin,
      'status' => $status,
    ]);
    $provider->save();
    return $provider;
  }

  /**
   * Creates a draft order for domains.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface[] $products
   *   The domains.
   * @param \Drupal\commerce_price\Price|null $price
   *   The price each sells for, instead of the variation's.
   */
  protected function createOrder(array $products, ?Price $price = NULL): OrderInterface {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $items = [];
    foreach ($products as $product) {
      $item = $storage->createFromPurchasableEntity($this->variation($product));
      if ($price) {
        $item->setUnitPrice($price, TRUE);
      }
      $item->save();
      $items[] = $item;
    }
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'mail' => 'buyer@example.com',
      'state' => 'draft',
      'order_items' => $items,
    ]);
    $order->save();
    return $order;
  }

  /**
   * Returns a domain's variation, whoever may see it.
   */
  protected function variation(ProductInterface $product): ProductVariationInterface {
    $variations = $product->getVariations();
    return reset($variations);
  }

  /**
   * Applies a transition to an order and saves it.
   */
  protected function transition(OrderInterface $order, string $transition): void {
    $order->getState()->applyTransitionById($transition);
    $order->save();
  }

  /**
   * Loads a product afresh.
   */
  protected function reload(ProductInterface $product): ProductInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $storage->resetCache([$product->id()]);
    /** @var \Drupal\commerce_product\Entity\ProductInterface */
    return $storage->load($product->id());
  }

  /**
   * Returns the owner mails of a kind.
   */
  protected function ownerMails(string $key): array {
    return $this->getMails(['module' => 'commerce_domains_owner', 'key' => $key]);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_checkout\Kernel;

use Drupal\commerce_checkout\Entity\CheckoutFlow;
use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\EventSubscriber\OrderSubscriber;
use Drupal\commerce_domains_checkout\EventSubscriber\CartPageRedirect;
use Drupal\commerce_domains_checkout\Form\DomainAddToCartForm;
use Drupal\commerce_domains_checkout\Plugin\Commerce\CheckoutFlow\DomainCheckoutFlow;
use Drupal\commerce_domains_checkout\Plugin\Commerce\CheckoutPane\DomainTransfer;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the domain checkout and selling a domain once.
 */
#[CoversClass(DomainCheckoutFlow::class)]
#[CoversClass(DomainTransfer::class)]
#[CoversClass(Transfer::class)]
#[CoversClass(DomainAddToCartForm::class)]
#[CoversClass(CartPageRedirect::class)]
#[CoversClass(OrderSubscriber::class)]
#[CoversClass(Availability::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainCheckoutTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_cart',
    'commerce_checkout',
    'commerce_domains_checkout',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig(['system', 'commerce_order', 'commerce_cart', 'commerce_checkout']);
    Provider::create(['id' => 'reg', 'label' => 'Dynadot', 'plugin' => 'test_registrar'])->save();
  }

  /**
   * Returns a domain's variation.
   */
  protected function variation(ProductInterface $product): ProductVariationInterface {
    $variations = $product->getVariations();
    return reset($variations);
  }

  /**
   * Returns a draft order for a domain.
   */
  protected function order(ProductInterface $product): OrderInterface {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $item = $storage->createFromPurchasableEntity($this->variation($product));
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'mail' => 'buyer@example.com',
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    $order->save();
    return $order;
  }

  /**
   * Tests the steps.
   */
  public function testFlow(): void {
    $flow = $this->container->get('plugin.manager.commerce_checkout_flow')->createInstance('commerce_domains');
    $steps = $flow->getSteps();
    $this->assertSame(['order_information', 'transfer', 'review', 'payment', 'complete'], array_keys($steps));
    $this->assertTrue($steps['complete']['hidden']);

    // Its configuration has a schema, which kernel tests check on save.
    CheckoutFlow::create([
      'id' => 'domains',
      'label' => 'Domains',
      'plugin' => 'commerce_domains',
      'configuration' => $flow->getConfiguration(),
    ])->save();
    $this->assertSame('commerce_domains', CheckoutFlow::load('domains')->getPluginId());
  }

  /**
   * Tests asking where the domain goes.
   */
  public function testTransfer(): void {
    $order = $this->order($this->createDomain('Transfer.com', 1000, ['domain_registrar' => 'reg']));
    $this->assertNull(Transfer::fromOrder($order));
    $this->assertSame('Dynadot', Transfer::currentRegistrar($order));

    $flow = $this->container->get('plugin.manager.commerce_checkout_flow')->createInstance('commerce_domains');
    $flow->setOrder($order);
    $pane = $this->container->get('plugin.manager.commerce_checkout_pane')->createInstance('commerce_domains_transfer', [], $flow);
    $complete_form = [];
    $form_state = new FormState();
    $pane_form = $pane->buildPaneForm(['#parents' => ['commerce_domains_transfer']], $form_state, $complete_form);
    $this->assertSame(Transfer::PUSH, $pane_form['method']['#default_value']);
    $this->assertStringContainsString('Dynadot', (string) $pane_form['method']['#options'][Transfer::PUSH]);
    $this->assertSame([':input[name="commerce_domains_transfer[method]"]' => ['value' => Transfer::PUSH]], $pane_form['account']['#states']['visible']);

    // Each choice needs its detail.
    foreach (['method', 'account', 'registrar'] as $key) {
      $pane_form[$key]['#parents'] = ['commerce_domains_transfer', $key];
    }
    $form_state->setValue('commerce_domains_transfer', [
      'method' => Transfer::TRANSFER,
      'registrar' => ' ',
      'account' => '',
    ]);
    $pane->validatePaneForm($pane_form, $form_state, $complete_form);
    $this->assertCount(1, $form_state->getErrors());

    $form_state = new FormState();
    $form_state->setValue('commerce_domains_transfer', [
      'method' => Transfer::PUSH,
      'account' => 'ana@example.com',
      'registrar' => '',
    ]);
    $pane->validatePaneForm($pane_form, $form_state, $complete_form);
    $this->assertCount(0, $form_state->getErrors());
    $pane->submitPaneForm($pane_form, $form_state, $complete_form);
    $this->assertEquals(new Transfer(Transfer::PUSH, 'ana@example.com'), Transfer::fromOrder($order));
    $this->assertSame('Push to the Dynadot account: ana@example.com', $order->getCustomerComments());
    $this->assertSame('Push to the Dynadot account: ana@example.com', $pane->buildPaneSummary()['#plain_text']);

    // Coming back to the step shows the answer.
    $pane_form = $pane->buildPaneForm(['#parents' => ['commerce_domains_transfer']], new FormState(), $complete_form);
    $this->assertSame('ana@example.com', $pane_form['account']['#default_value']);
    $this->assertSame('Your Dynadot account', (string) $pane_form['account']['#title']);

    // The seller reads the same answer; a push names the registrar if known.
    $this->assertSame('Transfer to Namecheap', (string) (new Transfer(Transfer::TRANSFER, registrar: 'Namecheap'))->describe('Dynadot'));
    $this->assertSame('Push to the account at the current registrar: ana', (string) (new Transfer(Transfer::PUSH, 'ana'))->describe(NULL));
    $unrecorded = $this->order($this->createDomain('Unrecorded.com'));
    $flow->setOrder($unrecorded);
    $pane = $this->container->get('plugin.manager.commerce_checkout_pane')->createInstance('commerce_domains_transfer', [], $flow);
    $pane_form = $pane->buildPaneForm(['#parents' => ['commerce_domains_transfer']], new FormState(), $complete_form);
    $this->assertSame('Your account at the current registrar', (string) $pane_form['account']['#title']);
  }

  /**
   * Tests that buying a domain empties the cart and opens checkout.
   */
  public function testBuyNow(): void {
    $this->assertSame(DomainAddToCartForm::class, $this->entityTypeManager->getDefinition('commerce_order_item')->getFormClass('add_to_cart'));
    $cart_manager = $this->container->get('commerce_cart.cart_manager');
    $cart = $this->container->get('commerce_cart.cart_provider')->createCart('default', $this->store);
    $cart_manager->addEntity($cart, $this->variation($this->createDomain('Earlier.com')));

    // The variation is chosen here rather than on the form.
    $this->container->get('entity_display.repository')->getFormDisplay('commerce_order_item', 'default', 'add_to_cart')->removeComponent('purchased_entity')->save();
    $variation = $this->variation($this->createDomain('Wanted.com'));
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item = $storage->createFromPurchasableEntity($variation);
    /** @var \Drupal\commerce_domains_checkout\Form\DomainAddToCartForm $form_object */
    $form_object = $this->entityTypeManager->getFormObject('commerce_order_item', 'add_to_cart');
    $form_object->setEntity($order_item);
    $form_object->setFormId('commerce_order_item_add_to_cart_form_test');
    $form_state = new FormState();
    $form_state->set('settings', ['combine' => TRUE]);
    $form_state->set('product', $variation->getProduct());
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $cart = Order::load($cart->id());
    $this->assertCount(1, $cart->getItems());
    $this->assertSame($variation->id(), $cart->getItems()[0]->getPurchasedEntityId());
    // Programmed submissions don't redirect; read what the form set.
    $redirect = $form_state->setProgrammed(FALSE)->getRedirect();
    $this->assertSame('commerce_checkout.form', $redirect->getRouteName());

    // The cart page sends the buyer to that checkout.
    $request = Request::create('/cart');
    $request->attributes->set('_route', 'commerce_cart.page');
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get(CartPageRedirect::class)->onRequest($event);
    $this->assertStringEndsWith('/checkout/' . $cart->id(), $event->getResponse()->headers->get('Location'));
  }

  /**
   * Tests that a domain sold by offer only can't be bought.
   */
  public function testOffersOnly(): void {
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $this->container->get('entity_display.repository')->getFormDisplay('commerce_order_item', 'default', 'add_to_cart')->removeComponent('purchased_entity')->save();
    $variation = $this->variation($this->createDomain('Offers.com', 1000, [], ['sale_buy_now' => FALSE]));
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    /** @var \Drupal\commerce_domains_checkout\Form\DomainAddToCartForm $form_object */
    $form_object = $this->entityTypeManager->getFormObject('commerce_order_item', 'add_to_cart');
    $form_object->setEntity($storage->createFromPurchasableEntity($variation));
    $form_object->setFormId('commerce_order_item_add_to_cart_form_offers');
    $form_state = new FormState();
    $form_state->set('settings', ['combine' => TRUE]);
    $form_state->set('product', $variation->getProduct());

    $form = $this->container->get('form_builder')->buildForm($form_object, $form_state);
    $this->assertFalse($form['actions']['submit']['#access']);
    // A crafted submission is refused too.
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame(['' => 'Offers.com is sold by offer only.'], array_map('strip_tags', array_map('strval', $form_state->getErrors())));
    $this->assertSame([], $storage->loadMultiple());
  }

  /**
   * Tests that placing an order takes its domain off sale.
   */
  public function testSold(): void {
    $product = $this->createDomain('Sold.com');
    $order = $this->order($product);
    $order->getState()->applyTransitionById('place');
    $order->save();
    $variation = ProductVariation::load($this->variation($product)->id());
    $this->assertFalse((bool) $variation->get('in_stock')->value);
    $this->assertTrue($variation->isPublished());
    $this->assertFalse($this->container->get(Availability::class)->markSold($variation));
  }

}

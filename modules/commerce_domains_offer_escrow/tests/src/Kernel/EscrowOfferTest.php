<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_offer_escrow\Kernel;

use Drupal\commerce_domains_checkout\UnpaidOrders;
use Drupal\commerce_domains_offer\Entity\Offer;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\Form\OfferForm;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_offer_escrow\EscrowOfferStarter;
use Drupal\commerce_domains_offer_escrow\EventSubscriber\EscrowOfferSubscriber;
use Drupal\commerce_domains_offer_escrow\Hook\EscrowOfferHooks;
use Drupal\commerce_escrow\EscrowOfferOrders;
use Drupal\commerce_escrow\Event\EscrowEvents;
use Drupal\commerce_escrow\Event\EscrowWebhookEvent;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests offers negotiated on Escrow.com.
 */
#[CoversClass(EscrowOfferStarter::class)]
#[CoversClass(EscrowOfferSubscriber::class)]
#[CoversClass(EscrowOfferHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class EscrowOfferTest extends DomainsKernelTestBase {

  use AssertMailTrait;

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
    'commerce_payment',
    'commerce_escrow',
    'commerce_domains_offer',
    'commerce_domains_offer_escrow',
    'commerce_domains_offer_escrow_test',
    'commerce_domains_checkout',
  ];

  /**
   * The starter.
   *
   * @var \Drupal\commerce_domains_offer_escrow\EscrowOfferStarter
   */
  protected EscrowOfferStarter $starter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_domains_offer');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig([
      'system',
      'commerce_order',
      'commerce_checkout',
      'commerce_payment',
      'commerce_domains_offer',
      'commerce_domains_offer_escrow',
    ]);
    $this->container->get('commerce_price.currency_importer')->import('CHF');
    PaymentGateway::create([
      'id' => 'escrow_offer_test',
      'label' => 'Escrow Offer',
      'plugin' => 'test_escrow_offer',
      'status' => TRUE,
    ])->save();
    $this->config('commerce_domains_offer_escrow.settings')->set('gateway', 'escrow_offer_test')->save();
    // Escrow.com's webhooks move orders through commerce_escrow's workflow.
    OrderType::load('default')->setWorkflowId('escrow_workflow')->save();
    $this->starter = $this->container->get(EscrowOfferStarter::class);
  }

  /**
   * Creates a domain and returns its variation.
   */
  protected function domain(string $name, Price $price): ProductVariationInterface {
    $variations = $this->createDomain($name, 1000, [], ['price' => $price])->getVariations();
    return reset($variations);
  }

  /**
   * Submits the offer form for a domain.
   */
  protected function submit(ProductVariationInterface $variation, array $values): FormStateInterface {
    $form_state = (new FormState())->setValues($values + [
      'name' => 'Ana',
      'mail' => 'ana@example.com',
      'op' => 'Make an offer',
    ]);
    $this->container->get('form_builder')->submitForm(OfferForm::class, $form_state, $variation);
    return $form_state;
  }

  /**
   * Returns a buyer's newest order.
   */
  protected function draft(string $mail = 'ana@example.com'): OrderInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $storage->resetCache();
    $orders = $storage->loadByProperties(['mail' => $mail]);
    ksort($orders);
    $order = end($orders);
    $this->assertInstanceOf(OrderInterface::class, $order);
    return $order;
  }

  /**
   * Sends the webhook event of an order.
   */
  protected function webhook(OrderInterface $order, string $event): void {
    $this->container->get('event_dispatcher')->dispatch(new EscrowWebhookEvent($order, ['event' => $event]), EscrowEvents::ESCROW_WEBHOOK);
  }

  /**
   * Has a buyer make the offer on Escrow.com: the first webhook arrives.
   */
  protected function makeOffer(string $mail = 'ana@example.com'): OfferInterface {
    $order = $this->draft($mail);
    $this->container->get('commerce_escrow.offer_orders')->place($order);
    $this->webhook($order, 'create');
    $offer = $this->starter->offerFor($order);
    $this->assertInstanceOf(OfferInterface::class, $offer);
    return $offer;
  }

  /**
   * Returns the offers a buyer made.
   *
   * @return \Drupal\commerce_domains_offer\Entity\OfferInterface[]
   *   The offers.
   */
  protected function offers(string $mail = 'ana@example.com'): array {
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface[] $offers */
    $offers = $this->entityTypeManager->getStorage('commerce_domains_offer')->loadByProperties(['mail' => $mail]);
    return $offers;
  }

  /**
   * Tests starting a negotiation from the offer form.
   */
  public function testForm(): void {
    $variation = $this->domain('escrow.com', new Price('2000', 'USD'));
    $form = $this->container->get('form_builder')->getForm(OfferForm::class, $variation);
    $this->assertArrayHasKey('escrow', $form);
    $this->assertFalse($form['amount']['#required'], 'The buyer names the amount on Escrow.com.');

    $form_state = $this->submit($variation, ['escrow' => 1, 'amount' => '']);
    $this->assertSame([], $form_state->getErrors());
    $response = $form_state->getResponse();
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertSame('https://escrow.test/offer?auction=5&email=ana%40example.com', $response->getTargetUrl());

    // Until the buyer makes the offer there is only a locked draft order: no
    // offer, nothing for the seller, and the domain stays for sale.
    $this->assertSame([], $this->offers());
    $this->assertCount(0, $this->getMails(['key' => 'new_offer']));
    $order = $this->draft();
    $this->assertSame('escrow_offer_test', $order->get('payment_gateway')->target_id);
    $this->assertSame('draft', $order->getState()->getId());
    $this->assertTrue($order->isLocked());
    $this->assertFalse((bool) $order->get('cart')->value);
    $this->assertEmpty($order->getOrderNumber());
    $this->assertEmpty($order->getPlacedTime());
    $this->assertTrue((bool) ProductVariation::load($variation->id())->get('in_stock')->value);
    $this->assertEquals(new Price('2000', 'USD'), $order->getItems()[0]->getUnitPrice());
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadMultipleByOrder($order);
    $this->assertSame(['77'], array_values(array_map(fn($payment) => $payment->getRemoteId(), $payments)));

    // The first webhook means the offer is made.
    $offer = $this->makeOffer();
    $this->assertSame(EscrowOfferStarter::SOURCE, $offer->getSource());
    $this->assertSame('pending', $offer->getState()->getId());
    $this->assertNull($offer->getAmount());
    $this->assertSame('Ana', $offer->get('name')->value);
    $order = $offer->getOrder();
    $this->assertSame('validation', $order->getState()->getId());
    $this->assertFalse($order->isLocked());
    $this->assertNotEmpty($order->getOrderNumber());
    $this->assertNotEmpty($order->getPlacedTime());
    $this->assertNull($order->getData(EscrowOfferOrders::DATA_KEY), 'The offer holds the buyer now.');
    $this->assertTrue((bool) ProductVariation::load($variation->id())->get('in_stock')->value, 'An offer does not sell the domain.');

    // Escrow.com writes to the buyer; the seller hears of the offer.
    $this->assertCount(0, $this->getMails(['key' => 'received']));
    $this->assertCount(1, $this->getMails(['key' => 'new_offer']));

    // A second webhook doesn't make it a second offer.
    $this->webhook($order, 'create');
    $this->assertCount(1, $this->offers());
    $this->assertCount(1, $this->getMails(['key' => 'new_offer']));

    // Without Escrow.com, it is an offer on the site as before. A
    // programmatic submit unchecks a checkbox with NULL.
    $this->assertArrayHasKey('amount', $this->submit($variation, ['escrow' => NULL, 'amount' => ''])->getErrors());
    $this->assertSame([], $this->submit($variation, ['escrow' => NULL, 'amount' => '1500'])->getErrors());
    $sources = array_map(fn(OfferInterface $offer) => $offer->getSource(), $this->offers());
    $this->assertEqualsCanonicalizing([EscrowOfferStarter::SOURCE, OfferInterface::SOURCE_SITE], array_values($sources));
  }

  /**
   * Tests that an amount with the Escrow.com choice starts nothing.
   *
   * The field is hidden with that choice, so this is a bot filling in the form.
   */
  public function testAmountWithEscrowIsRefused(): void {
    $variation = $this->domain('filled.com', new Price('2000', 'USD'));
    $form = $this->container->get('form_builder')->getForm(OfferForm::class, $variation);
    $this->assertContains('commerce_domains_offer_escrow/offer_form', $form['#attached']['library']);

    $form_state = $this->submit($variation, ['escrow' => 1, 'amount' => '1500']);
    $this->assertArrayHasKey('amount', $form_state->getErrors());
    $this->assertNull($form_state->getResponse());
    $this->assertSame([], $this->offers());
    $this->assertSame([], $this->entityTypeManager->getStorage('commerce_order')->loadMultiple());
    $this->assertCount(0, $this->getMails(['key' => 'new_offer']));
  }

  /**
   * Tests that a refused negotiation leaves nothing behind.
   */
  public function testRefused(): void {
    $this->container->get('state')->set('commerce_domains_offer_escrow_test.fail', TRUE);
    $form_state = $this->submit($this->domain('refused.com', new Price('2000', 'USD')), ['escrow' => 1]);
    $this->assertNull($form_state->getResponse());
    $this->assertSame([], $this->offers());
    $this->assertSame([], $this->entityTypeManager->getStorage('commerce_order')->loadMultiple());
  }

  /**
   * Tests that a deal on Escrow.com isn't canceled as an unpaid order.
   *
   * Its order is placed from the start, and nothing is paid on it here, so with
   * a price agreed it looks just like an order left unpaid.
   */
  public function testUnpaidHoldLeavesEscrowAlone(): void {
    $this->installConfig(['commerce_domains_checkout']);
    $unpaid = $this->container->get(UnpaidOrders::class);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('state')->set(UnpaidOrders::STATE_SINCE, $now - 100 * 86400);
    $this->submit($this->domain('longdeal.com', new Price('2000', 'USD')), ['escrow' => 1, 'amount' => '']);
    $order = $this->makeOffer()->getOrder();
    $item = $order->getItems()[0];
    $item->setUnitPrice(new Price('2000', 'USD'), TRUE)->save();
    $order->recalculateTotalPrice();
    $order->setPlacedTime($now - 30 * 86400)->save();
    $this->assertSame('validation', $order->getState()->getId());
    $this->assertFalse($order->isPaid());

    // Its own payment is enough to keep it, and so is being paid through
    // escrow, which is what's left once that is gone.
    $this->assertSame(0, $unpaid->process());
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $storage->delete($storage->loadMultiple());
    $this->assertSame(0, $unpaid->process());

    $this->entityTypeManager->getStorage('commerce_order')->resetCache();
    $this->assertSame('validation', Order::load($order->id())->getState()->getId());
  }

  /**
   * Tests that a payment takes the domain off sale without offer_accepted.
   *
   * @param string $event
   *   The first webhook that shows both sides have settled.
   */
  #[DataProvider('settledEvents')]
  public function testSoldWithoutOfferAccepted(string $event): void {
    $variation = $this->domain('settled.com', new Price('2000', 'USD'));
    $this->starter->start($variation, ['mail' => 'ana@example.com'], new AnonymousUserSession(), $this->store);
    $offer = $this->makeOffer();
    $this->assertTrue((bool) ProductVariation::load($variation->id())->get('in_stock')->value);

    $this->webhook($offer->getOrder(), $event);
    $this->assertFalse((bool) ProductVariation::load($variation->id())->get('in_stock')->value);
    $this->assertSame('accepted', Offer::load($offer->id())->getState()->getId());
  }

  /**
   * Returns the webhooks that show both sides have settled.
   */
  public static function settledEvents(): array {
    return [['offer_accepted'], ['payment_sent'], ['payment_received'], ['payment_approved']];
  }

  /**
   * Tests that other webhooks leave the domain for sale.
   */
  public function testStaysForSaleWhileNegotiating(): void {
    $variation = $this->domain('talking.com', new Price('2000', 'USD'));
    $this->starter->start($variation, ['mail' => 'ana@example.com'], new AnonymousUserSession(), $this->store);
    $offer = $this->makeOffer();
    foreach (['create', 'agree', 'party_verification_submitted'] as $event) {
      $this->webhook($offer->getOrder(), $event);
    }
    $this->assertTrue((bool) ProductVariation::load($variation->id())->get('in_stock')->value);
    $this->assertSame('pending', Offer::load($offer->id())->getState()->getId());
  }

  /**
   * Tests that the offer follows Escrow.com.
   */
  public function testFollowsEscrow(): void {
    $variation = $this->domain('follow.com', new Price('2000', 'USD'));
    $this->starter->start($variation, ['mail' => 'ana@example.com'], new AnonymousUserSession(), $this->store);
    $offer = $this->makeOffer();
    $order = $offer->getOrder();

    // Only the Escrow Offer gateway's conditions match the order.
    $condition = $this->container->get('plugin.manager.commerce_condition')->createInstance('commerce_domains_order_escrow_offer');
    $this->assertTrue($condition->evaluate($order));
    $condition->setConfiguration(['escrow_offer' => FALSE]);
    $this->assertFalse($condition->evaluate($order));

    // Another variation of the domain is for sale too.
    $other = ProductVariation::create([
      'type' => 'default',
      'sku' => 'follow.com-other',
      'price' => new Price('2500', 'USD'),
      'status' => TRUE,
      'in_stock' => TRUE,
    ]);
    $other->save();
    $variation->getProduct()->addVariation($other)->save();

    $order->getState()->applyTransitionById('offer_accepted');
    $order->save();
    $this->assertSame('accepted', Offer::load($offer->id())->getState()->getId());
    // The domain is sold, whichever variation the offer was on.
    $this->assertFalse((bool) ProductVariation::load($variation->id())->get('in_stock')->value);
    $this->assertFalse((bool) ProductVariation::load($other->id())->get('in_stock')->value, 'Its other variation is off sale too.');

    // Escrow.com settles the price on the order before the payment.
    $order->getItems()[0]->setUnitPrice(new Price('1800', 'USD'), TRUE)->save();
    $order->recalculateTotalPrice()->save();
    $this->container->get('event_dispatcher')->dispatch(new EscrowWebhookEvent($order, ['event' => 'payment_sent']), EscrowEvents::ESCROW_WEBHOOK);
    $this->assertEquals(new Price('1800', 'USD'), Offer::load($offer->id())->getAmount());

    $order->getState()->applyTransitionById('payment_approved');
    $order->save();
    $this->assertSame('purchased', Offer::load($offer->id())->getState()->getId());

    // A cancelled negotiation declines its offer.
    $this->starter->start($this->domain('cancel.com', new Price('3000', 'USD')), ['mail' => 'bo@example.com'], new AnonymousUserSession(), $this->store);
    $cancelled = $this->makeOffer('bo@example.com');
    $this->container->get('event_dispatcher')->dispatch(new EscrowWebhookEvent($cancelled->getOrder(), ['event' => 'cancel']), EscrowEvents::ESCROW_WEBHOOK);
    $this->assertSame('declined', Offer::load($cancelled->id())->getState()->getId());
    $this->assertCount(0, $this->getMails(['key' => 'declined']));
  }

  /**
   * Tests the offer condition without a gateway named in the settings.
   */
  public function testConditionWithoutGateway(): void {
    $this->config('commerce_domains_offer_escrow.settings')->set('gateway', '')->save();
    // Left unsaved: commerce_escrow has no config schema for its gateways.
    $gateway = PaymentGateway::create([
      'id' => 'escrow_offer',
      'label' => 'Escrow Offer',
      'plugin' => 'escrow_offer',
      'status' => TRUE,
    ]);
    $condition = $this->container->get('plugin.manager.commerce_condition')->createInstance('commerce_domains_order_escrow_offer');
    $order = Order::create(['type' => 'default', 'payment_gateway' => $gateway]);
    $this->assertTrue($condition->evaluate($order), 'Offers go to the first enabled Escrow Offer gateway.');
    $this->assertFalse($condition->evaluate($order->set('payment_gateway', NULL)));
    $condition->setConfiguration(['escrow_offer' => FALSE]);
    $this->assertTrue($condition->evaluate($order), 'Other orders.');
  }

  /**
   * Tests when the choice is left out, and that expiry leaves these alone.
   */
  public function testLimits(): void {
    $form_builder = $this->container->get('form_builder');
    $this->assertArrayNotHasKey('escrow', $form_builder->getForm(OfferForm::class, $this->domain('franc.com', new Price('2000', 'CHF'))), 'Escrow.com takes no francs.');

    $variation = $this->domain('quiet.com', new Price('2000', 'USD'));
    $this->starter->start($variation, ['mail' => 'ana@example.com'], new AnonymousUserSession(), $this->store);
    $this->makeOffer();
    $this->container->get('database')->update('commerce_domains_offer')->fields(['changed' => 1])->execute();
    $this->entityTypeManager->getStorage('commerce_domains_offer')->resetCache();
    $this->assertSame(0, $this->container->get(OfferManager::class)->expireStale(), 'Escrow.com decides when its offers end.');

    PaymentGateway::load('escrow_offer_test')->disable()->save();
    $this->assertArrayNotHasKey('escrow', $form_builder->getForm(OfferForm::class, $variation));
  }

}

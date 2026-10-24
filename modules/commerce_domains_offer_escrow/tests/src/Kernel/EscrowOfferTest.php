<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_offer_escrow\Kernel;

use Drupal\commerce_domains_offer\Entity\Offer;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\Form\OfferForm;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_offer_escrow\EscrowOfferStarter;
use Drupal\commerce_domains_offer_escrow\EventSubscriber\EscrowOfferSubscriber;
use Drupal\commerce_domains_offer_escrow\Hook\EscrowOfferHooks;
use Drupal\commerce_escrow\Event\EscrowEvents;
use Drupal\commerce_escrow\Event\EscrowWebhookEvent;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
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

    $offers = $this->offers();
    $this->assertCount(1, $offers);
    $offer = reset($offers);
    $this->assertSame(EscrowOfferStarter::SOURCE, $offer->getSource());
    $this->assertSame('pending', $offer->getState()->getId());
    $this->assertNull($offer->getAmount());
    $order = $offer->getOrder();
    $this->assertSame('escrow_offer_test', $order->get('payment_gateway')->target_id);
    $this->assertSame('validation', $order->getState()->getId());
    $this->assertNotEmpty($order->getOrderNumber());
    $this->assertNotEmpty($order->getPlacedTime());
    $this->assertEquals(new Price('2000', 'USD'), $order->getItems()[0]->getUnitPrice());
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadMultipleByOrder($order);
    $this->assertSame(['77'], array_values(array_map(fn($payment) => $payment->getRemoteId(), $payments)));

    // Escrow.com writes to the buyer; the seller hears of the offer.
    $this->assertCount(0, $this->getMails(['key' => 'received']));
    $this->assertCount(1, $this->getMails(['key' => 'new_offer']));

    // Without Escrow.com, it is an offer on the site as before. A
    // programmatic submit unchecks a checkbox with NULL.
    $this->assertArrayHasKey('amount', $this->submit($variation, ['escrow' => NULL, 'amount' => ''])->getErrors());
    $this->assertSame([], $this->submit($variation, ['escrow' => NULL, 'amount' => '1500'])->getErrors());
    $sources = array_map(fn(OfferInterface $offer) => $offer->getSource(), $this->offers());
    $this->assertEqualsCanonicalizing([EscrowOfferStarter::SOURCE, OfferInterface::SOURCE_SITE], array_values($sources));
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
   * Tests that the offer follows Escrow.com.
   */
  public function testFollowsEscrow(): void {
    $variation = $this->domain('follow.com', new Price('2000', 'USD'));
    $this->starter->start($variation, ['mail' => 'ana@example.com'], new AnonymousUserSession(), $this->store);
    $offer = current($this->offers());
    $order = $offer->getOrder();

    $order->getState()->applyTransitionById('offer_accepted');
    $order->save();
    $this->assertSame('accepted', Offer::load($offer->id())->getState()->getId());

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
    $cancelled = current($this->offers('bo@example.com'));
    $this->container->get('event_dispatcher')->dispatch(new EscrowWebhookEvent($cancelled->getOrder(), ['event' => 'cancel']), EscrowEvents::ESCROW_WEBHOOK);
    $this->assertSame('declined', Offer::load($cancelled->id())->getState()->getId());
    $this->assertCount(0, $this->getMails(['key' => 'declined']));
  }

  /**
   * Tests when the choice is left out, and that expiry leaves these alone.
   */
  public function testLimits(): void {
    $form_builder = $this->container->get('form_builder');
    $this->assertArrayNotHasKey('escrow', $form_builder->getForm(OfferForm::class, $this->domain('franc.com', new Price('2000', 'CHF'))), 'Escrow.com takes no francs.');

    $variation = $this->domain('quiet.com', new Price('2000', 'USD'));
    $this->starter->start($variation, ['mail' => 'ana@example.com'], new AnonymousUserSession(), $this->store);
    $this->container->get('database')->update('commerce_domains_offer')->fields(['changed' => 1])->execute();
    $this->entityTypeManager->getStorage('commerce_domains_offer')->resetCache();
    $this->assertSame(0, $this->container->get(OfferManager::class)->expireStale(), 'Escrow.com decides when its offers end.');

    PaymentGateway::load('escrow_offer_test')->disable()->save();
    $this->assertArrayNotHasKey('escrow', $form_builder->getForm(OfferForm::class, $variation));
  }

}

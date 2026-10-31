<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_lease\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\commerce_domains_lease\Entity\Lease;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\EventSubscriber\CheckoutSubscriber;
use Drupal\commerce_domains_lease\LeaseCalculator;
use Drupal\commerce_domains_lease\LeaseManager;
use Drupal\commerce_domains_lease\Plugin\AdvancedQueue\JobType\LeaseChargeJob;
use Drupal\commerce_domains_lease\Plugin\Commerce\CheckoutPane\LeaseToOwn;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests leasing a domain: terms, the down payment and the monthly payments.
 */
#[CoversClass(LeaseCalculator::class)]
#[CoversClass(LeaseManager::class)]
#[CoversClass(CheckoutSubscriber::class)]
#[CoversClass(LeaseChargeJob::class)]
#[CoversClass(LeaseToOwn::class)]
#[CoversClass(Lease::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class LeaseTest extends DomainsKernelTestBase {

  use AssertMailTrait;
  use UserCreationTrait;

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
    'commerce_payment_example',
    'advancedqueue',
    'commerce_domains_checkout',
    'commerce_domains_lease',
  ];

  /**
   * The buyer.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $buyer;

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
    $this->installEntitySchema('commerce_domains_lease');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installConfig([
      'system',
      'commerce_order',
      'commerce_cart',
      'commerce_checkout',
      'commerce_payment',
      'commerce_domains_lease',
    ]);
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');

    PaymentGateway::create(['id' => 'card', 'label' => 'Card', 'plugin' => 'example_onsite'])->save();
    PaymentGateway::create(['id' => 'bank', 'label' => 'Bank', 'plugin' => 'manual'])->save();
    $this->config('commerce_domains_lease.settings')->set('gateway_plugins', ['example_onsite'])->save();
    $this->config('system.site')->set('mail', 'owner@example.com')->save();
    $this->buyer = $this->createUser([], NULL, FALSE, ['mail' => 'buyer@example.com']);
  }

  /**
   * Returns a draft order for a domain, leased over some months.
   */
  protected function order(ProductInterface $product, ?int $months = NULL): OrderInterface {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $variations = $product->getVariations();
    $item = $storage->createFromPurchasableEntity(reset($variations));
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->buyer->id(),
      'mail' => 'buyer@example.com',
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    if ($months) {
      $order->setData(LeaseCalculator::DATA_KEY, ['months' => $months]);
    }
    $order->save();
    return $order;
  }

  /**
   * Returns the buyer's saved card, which declines with postal code 53140.
   */
  protected function card(string $postal_code = '10000'): PaymentMethod {
    $profile = Profile::create([
      'type' => 'customer',
      'uid' => 0,
      'address' => ['country_code' => 'US', 'postal_code' => $postal_code],
    ]);
    $profile->save();
    $card = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'card',
      'uid' => $this->buyer->id(),
      'remote_id' => 'tok_1',
      'reusable' => TRUE,
      'billing_profile' => $profile,
    ]);
    $card->save();
    return $card;
  }

  /**
   * Pays an order's down payment with a card, and places it.
   */
  protected function checkout(OrderInterface $order, PaymentMethod $card): void {
    $plan = $this->container->get(LeaseCalculator::class)->plan($order, LeaseCalculator::chosenMonths($order));
    Payment::create([
      'state' => 'completed',
      'amount' => $plan->downPayment,
      'payment_gateway' => 'card',
      'payment_method' => $card->id(),
      'order_id' => $order->id(),
    ])->save();
    $this->container->get('commerce_payment.order_updater')->destruct();
    $order = Order::load($order->id());
    $order->set('payment_method', $card);
    $order->getState()->applyTransitionById('place');
    $order->save();
  }

  /**
   * Returns the lease of an order, loaded afresh.
   */
  protected function lease(OrderInterface $order): LeaseInterface {
    $this->entityTypeManager->getStorage('commerce_domains_lease')->resetCache();
    $lease = $this->container->get(LeaseManager::class)->forOrder($order);
    $this->assertNotNull($lease);
    return $lease;
  }

  /**
   * Tests the terms offered and what each costs.
   */
  public function testTermsAndPlan(): void {
    $calculator = $this->container->get(LeaseCalculator::class);
    $this->assertSame([6], $calculator->terms($this->order($this->createDomain('Six.com', 6000))));
    $this->assertSame([6, 12, 24], $calculator->terms($this->order($this->createDomain('Big.com', 30000))));
    $this->assertSame([], $calculator->terms($this->order($this->createDomain('Cheap.com', 500))));
    $this->assertSame([2], $calculator->terms($this->order($this->createDomain('Two.com', 500, [], ['sale_lease_months' => 2]))));
    $this->assertSame([], $calculator->terms($this->order($this->createDomain('Offers.com', 30000, [], ['sale_buy_now' => FALSE]))));

    // 10% down by default; the variation can set its own.
    $plan = $calculator->plan($this->order($this->createDomain('Plan.com', 30000)), 12);
    $this->assertEquals(new Price('3000', 'USD'), $plan->downPayment);
    $this->assertEquals(new Price('2250', 'USD'), $plan->installment);
    $this->assertEquals(new Price('2250', 'USD'), $plan->lastInstallment());
    $plan = $calculator->plan($this->order($this->createDomain('Own.com', 30000, [], ['sale_lease_down_payment' => 25])), 12);
    $this->assertEquals(new Price('7500', 'USD'), $plan->downPayment);

    // The last payment makes up the rounding.
    $plan = LeaseCalculator::planFor(new Price('1000', 'USD'), 7, 10, $this->container->get('commerce_price.rounder'));
    $this->assertEquals(new Price('128.57', 'USD'), $plan->installment);
    $this->assertEquals(new Price('128.58', 'USD'), $plan->lastInstallment());
  }

  /**
   * Tests the gateways a leased order offers, and the Stripe down payment.
   */
  public function testCheckout(): void {
    $subscriber = $this->container->get(CheckoutSubscriber::class);
    $gateways = PaymentGateway::loadMultiple();
    $full = $this->order($this->createDomain('Full.com', 30000));
    $event = new FilterPaymentGatewaysEvent($gateways, $full);
    $subscriber->onFilterGateways($event);
    $this->assertEqualsCanonicalizing(['bank', 'card'], array_keys($event->getPaymentGateways()));
    $leased = $this->order($this->createDomain('Leased.com', 30000), 12);
    $event = new FilterPaymentGatewaysEvent($gateways, $leased);
    $subscriber->onFilterGateways($event);
    $this->assertSame(['card'], array_keys($event->getPaymentGateways()));

    // Stripe charges the down payment, and keeps the card.
    $intent = $this->intent($leased, ['amount' => 3000000, 'currency' => 'usd']);
    $subscriber->onStripeIntent($intent);
    $this->assertSame(300000, $intent->attributes['amount']);
    $this->assertSame('off_session', $intent->attributes['setup_future_usage']);
    // Not for an order paid in full, nor a later payment.
    $intent = $this->intent($full, ['amount' => 3000000]);
    $subscriber->onStripeIntent($intent);
    $this->assertSame(['amount' => 3000000], $intent->attributes);
    $intent = $this->intent($leased, ['amount' => 90000, 'off_session' => TRUE]);
    $subscriber->onStripeIntent($intent);
    $this->assertSame(90000, $intent->attributes['amount']);
  }

  /**
   * Returns a stand-in for Commerce Stripe's PaymentIntentCreateEvent.
   */
  protected function intent(OrderInterface $order, array $attributes): object {
    return new class($order, $attributes) {

      public function __construct(protected OrderInterface $order, public array $attributes) {}

      /**
       * Returns the order.
       */
      public function getOrder(): OrderInterface {
        return $this->order;
      }

      /**
       * Returns the intent's attributes.
       */
      public function getIntentAttributes(): array {
        return $this->attributes;
      }

      /**
       * Sets the intent's attributes.
       */
      public function setIntentAttributes(array $attributes): void {
        $this->attributes = $attributes;
      }

    };
  }

  /**
   * Tests the checkout pane: the choice, and buyers without an account.
   */
  public function testPane(): void {
    $order = $this->order($this->createDomain('Pane.com', 30000));
    $pane = $this->pane($order);
    $this->assertTrue($pane->isVisible());
    $complete_form = [];
    $pane_form = $pane->buildPaneForm(['#parents' => ['lease']], new FormState(), $complete_form);
    $this->assertSame(['full', 6, 12, 24], array_keys($pane_form['choice']['#options']));
    $this->assertSame('Lease to own: $3,000.00 now, then 12 monthly payments of $2,250.00 ($30,000.00 in all)', (string) $pane_form['choice']['#options'][12]);
    $this->assertArrayNotHasKey('account', $pane_form);

    $form_state = (new FormState())->setValue('lease', ['choice' => '12']);
    $pane->validatePaneForm($pane_form, $form_state, $complete_form);
    $this->assertSame([], $form_state->getErrors());
    $pane->submitPaneForm($pane_form, $form_state, $complete_form);
    $this->assertSame(12, LeaseCalculator::chosenMonths($order));
    $this->assertStringContainsString('12 monthly payments', (string) $pane->buildPaneSummary()['#plain_text']);
    $pane->submitPaneForm($pane_form, (new FormState())->setValue('lease', ['choice' => 'full']), $complete_form);
    $this->assertNull(LeaseCalculator::chosenMonths($order));

    // Buyers without an account are asked to log in.
    $order->setCustomerId(0);
    $pane_form = $pane->buildPaneForm(['#parents' => ['lease']], new FormState(), $complete_form);
    $this->assertStringContainsString('log in', (string) $pane_form['account']['#markup']);
    // As the form builder sets it.
    $pane_form['choice']['#parents'] = ['lease', 'choice'];
    $form_state = (new FormState())->setValue('lease', ['choice' => '12']);
    $pane->validatePaneForm($pane_form, $form_state, $complete_form);
    $this->assertCount(1, $form_state->getErrors());

    // Hidden where no lease is possible, or no gateway takes one.
    $this->assertFalse($this->pane($this->order($this->createDomain('Small.com', 500)))->isVisible());
    PaymentGateway::load('card')->setStatus(FALSE)->save();
    $this->assertFalse($this->pane($order)->isVisible());
  }

  /**
   * Returns the lease pane for an order.
   */
  protected function pane(OrderInterface $order): LeaseToOwn {
    $flow = $this->container->get('plugin.manager.commerce_checkout_flow')->createInstance('commerce_domains');
    $flow->setOrder($order);
    $pane = $this->container->get('plugin.manager.commerce_checkout_pane')->createInstance('commerce_domains_lease', [], $flow);
    $this->assertInstanceOf(LeaseToOwn::class, $pane);
    return $pane;
  }

  /**
   * Tests a lease from checkout to the last payment, with a missed one.
   */
  public function testPayments(): void {
    $this->config('commerce_domains_lease.settings')->set('max_retries', 1)->save();
    $order = $this->order($this->createDomain('Lease.com', 6000), 6);
    $card = $this->card();
    $this->checkout($order, $card);

    $lease = $this->lease($order);
    $this->assertSame(LeaseInterface::ACTIVE, $lease->getState());
    $this->assertSame('lease.com', $lease->getDomain());
    $this->assertEquals(new Price('600', 'USD'), $lease->getPaid());
    $this->assertEquals(new Price('900', 'USD'), $lease->getInstallment());
    $this->assertSame(6, $lease->getMonths());
    $placed = (int) Order::load($order->id())->getPlacedTime();
    $this->assertSame(LeaseManager::addMonths($placed, 1), $lease->getNextDue());
    $this->assertSame('Your lease of lease.com', (string) $this->getMails(['key' => 'started'])[0]['subject']);
    $this->assertFalse(Order::load($order->id())->isPaid(), 'Paid once the lease is.');

    // Nothing is due for a month.
    $manager = $this->container->get(LeaseManager::class);
    $this->assertSame([], $manager->due());
    $lease->set('next_due', $placed - 1)->save();
    $this->assertSame(1, $manager->queueDue());
    $job = Queue::load(LeaseManager::QUEUE)->getBackend()->claimJob();
    $this->assertSame('Paid.', $this->container->get('plugin.manager.advancedqueue_job_type')->createInstance(LeaseChargeJob::ID)->process($job)->getMessage());
    $lease = $this->lease($order);
    $this->assertEquals(new Price('1500', 'USD'), $lease->getPaid());
    $this->assertSame(1, $lease->getPaymentsMade());
    $this->assertSame(LeaseManager::addMonths($placed - 1, 1), $lease->getNextDue(), 'A month after it was due.');

    // A declined card is retried, then the lease is in default.
    $card->getBillingProfile()->set('address', ['country_code' => 'US', 'postal_code' => '53140'])->save();
    $this->assertFalse($manager->charge($lease));
    $lease = $this->lease($order);
    $this->assertSame(LeaseInterface::OVERDUE, $lease->getState());
    $this->assertSame(1, $lease->getFailures());
    $this->assertSame($this->container->get('datetime.time')->getRequestTime() + 3 * 86400, $lease->getNextDue());
    $this->assertCount(2, $this->getMails(['key' => 'failed']), 'The buyer and the owner.');
    $this->assertFalse($manager->charge($lease));
    $lease = $this->lease($order);
    $this->assertSame(LeaseInterface::DEFAULTED, $lease->getState());
    $this->assertNull($lease->getNextDue());
    $this->assertSame([], $manager->due(), 'Nothing more is charged.');
    $mails = $this->getMails(['key' => 'defaulted', 'to' => 'owner@example.com']);
    $this->assertSame('Lease in default: lease.com', (string) $mails[0]['subject']);

    // The owner charges it by hand once the card works; the rest follows.
    $card->getBillingProfile()->set('address', ['country_code' => 'US', 'postal_code' => '10000'])->save();
    $this->assertTrue($manager->charge($this->lease($order)));
    for ($i = 0; $i < 4; $i++) {
      $this->assertTrue($manager->charge($this->lease($order)));
    }
    $lease = $this->lease($order);
    $this->assertSame(LeaseInterface::COMPLETED, $lease->getState());
    $this->assertTrue($lease->getRemaining()->isZero());
    $this->assertFalse($manager->charge($lease));
    $this->container->get('commerce_payment.order_updater')->destruct();
    $this->assertTrue(Order::load($order->id())->isPaid());
    $this->assertCount(2, $this->getMails(['key' => 'completed']));
  }

}

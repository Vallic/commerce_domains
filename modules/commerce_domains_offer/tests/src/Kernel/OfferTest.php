<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_offer\Kernel;

use Drupal\commerce\Context;
use Drupal\commerce_domains_offer\Entity\Offer;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\Event\OfferAllowedEvent;
use Drupal\commerce_domains_offer\Form\OfferAdminForm;
use Drupal\commerce_domains_offer\Form\OfferBuyerForm;
use Drupal\commerce_domains_offer\Form\OfferForm;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_offer\OfferPolicy;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests offers from submission to purchase.
 */
#[CoversClass(Offer::class)]
#[CoversClass(OfferManager::class)]
#[CoversClass(OfferPolicy::class)]
#[CoversClass(OfferForm::class)]
#[CoversClass(OfferBuyerForm::class)]
#[CoversClass(OfferAdminForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class OfferTest extends DomainsKernelTestBase {

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
    'commerce_domains_offer',
  ];

  /**
   * The offer manager.
   *
   * @var \Drupal\commerce_domains_offer\OfferManager
   */
  protected OfferManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_domains_offer');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig(['system', 'commerce_order', 'commerce_checkout', 'commerce_domains_offer']);
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $this->manager = $this->container->get(OfferManager::class);
  }

  /**
   * Creates a domain and returns its variation.
   */
  protected function domain(string $name, float $usd = 2000, array $variation = []): ProductVariationInterface {
    $variations = $this->createDomain($name, $usd, [], $variation)->getVariations();
    return reset($variations);
  }

  /**
   * Makes an offer as an anonymous buyer.
   */
  protected function offer(ProductVariationInterface $variation, string $usd = '1500'): OfferInterface {
    return $this->manager->submit($variation, new Price($usd, 'USD'), ['mail' => 'buyer@example.com', 'name' => 'Ana'], new AnonymousUserSession());
  }

  /**
   * Returns the subjects of the mails sent to an address.
   */
  protected function subjects(string $to): array {
    return array_column($this->getMails(['to' => $to]), 'subject');
  }

  /**
   * Tests which domains take offers, and the least they take.
   */
  public function testPolicy(): void {
    $policy = $this->container->get(OfferPolicy::class);
    $plain = $this->domain('plain.com', 2000);
    $this->assertTrue($policy->allows($plain));
    $this->assertFalse($policy->allows($this->domain('fixed.com', 2000, ['sale_offers' => FALSE])));
    $this->assertFalse($policy->allows($this->domain('sold.com', 2000, ['in_stock' => FALSE])));

    // The rule: half the price below 10,000, a quarter above.
    $this->assertEquals(new Price('1000', 'USD'), $policy->minimum($plain, new Price('2000', 'USD')));
    $this->assertEquals(new Price('5000', 'USD'), $policy->minimum($plain, new Price('20000', 'USD')));
    // The domain's own minimum, moved with the price into euros.
    $own = $this->domain('own.com', 2000, ['sale_minimum_offer' => new Price('1600', 'USD')]);
    $this->assertEquals(new Price('1600', 'USD'), $policy->minimum($own, new Price('2000', 'USD')));
    $this->assertEquals(new Price('1440', 'EUR'), $policy->minimum($own, new Price('1800', 'EUR')));
    $this->assertEquals(new Price('2000', 'USD'), $policy->price($own, new Context(new AnonymousUserSession(), $this->store)));

    // The settings: a least price, and none on Atom premium names.
    $premium = $this->domain('premium.com', 20000);
    $premium->getProduct()->set('atom_premium', TRUE)->save();
    $this->assertTrue($policy->allows($premium));
    $this->config('commerce_domains_offer.settings')->set('minimum_price', 10000)->set('premium_offers', FALSE)->save();
    $this->assertFalse($policy->allows($plain));
    $this->assertTrue($policy->allows($this->domain('dear.com', 10000)));
    $this->assertFalse($policy->allows($premium));
    $this->config('commerce_domains_offer.settings')->set('minimum_price', 0)->set('premium_offers', TRUE)->save();

    // Sites add their own rules.
    $this->container->get('event_dispatcher')->addListener(OfferAllowedEvent::NAME, fn(OfferAllowedEvent $event) => $event->setAllowed(FALSE));
    $this->assertFalse($policy->allows($plain));
  }

  /**
   * Tests the seller accepting, the order and its purchase.
   */
  public function testSellerAccepts(): void {
    $offer = $this->offer($this->domain('Accept.com'));
    $this->assertSame('pending', $offer->getState()->getId());
    $this->assertSame('accept.com', $offer->getDomain());
    $this->assertSame(['Your offer on accept.com'], $this->subjects('buyer@example.com'));
    $this->assertSame(['New offer on accept.com'], $this->subjects($this->store->getEmail()));
    $this->assertNotSame('', $offer->getToken());

    $this->manager->accept($offer);
    $this->assertSame('accepted', $offer->getState()->getId());
    $order = $offer->getOrder();
    $this->assertInstanceOf(OrderInterface::class, $order);
    $this->assertSame('draft', $order->getState()->getId());
    $this->assertSame('buyer@example.com', $order->getEmail());
    $item = $order->getItems()[0];
    $this->assertEquals(new Price('1500', 'USD'), $item->getUnitPrice());
    $this->assertTrue($item->isUnitPriceOverridden(), 'Refreshing the order keeps the agreed amount.');
    $this->assertSame((int) $offer->id(), (int) $item->getData(OfferManager::ORDER_ITEM_KEY));
    $accepted = $this->getMails(['to' => 'buyer@example.com', 'key' => 'accepted']);
    $this->assertStringContainsString($this->manager->buyerUrl($offer)->toString(), (string) end($accepted)['body']);

    // An anonymous buyer gets the order in their session to pay it.
    $this->assertNotNull($this->manager->checkoutUrl($offer, new AnonymousUserSession()));
    $this->assertTrue($this->container->get('commerce_cart.cart_session')->hasCartId($order->id()));

    $order->getState()->applyTransitionById('place');
    $order->save();
    $this->assertSame('purchased', Offer::load($offer->id())->getState()->getId());
  }

  /**
   * Tests counter-offers, which the buyer accepts or declines.
   */
  public function testCounterOffers(): void {
    $offer = $this->offer($this->domain('counter.com'));
    $this->manager->counter($offer, new Price('1800', 'USD'));
    $this->assertSame('countered', $offer->getState()->getId());
    $this->assertCount(1, $this->getMails(['to' => 'buyer@example.com', 'key' => 'countered']));

    $this->manager->accept($offer);
    $this->assertEquals(new Price('1800', 'USD'), $offer->getOrder()->getItems()[0]->getUnitPrice());
    $this->assertCount(1, $this->getMails(['key' => 'buyer_accepted']));

    $declined = $this->offer($this->domain('declined.com'));
    $this->manager->counter($declined, new Price('1900', 'USD'));
    $this->manager->decline($declined);
    $this->assertSame('declined', $declined->getState()->getId());
    $this->assertCount(1, $this->getMails(['key' => 'buyer_declined']));
    $this->assertNull($declined->getOrder());
  }

  /**
   * Tests expiring unanswered and unpaid offers.
   */
  public function testExpiry(): void {
    $pending = $this->offer($this->domain('quiet.com'));
    $unpaid = $this->offer($this->domain('unpaid.com'));
    $this->manager->accept($unpaid);
    $fresh = $this->offer($this->domain('fresh.com'));
    $old = $this->container->get('datetime.time')->getRequestTime() - 15 * 86400;
    $database = $this->container->get('database');
    $database->update('commerce_domains_offer')
      ->fields(['changed' => $old])
      ->condition('offer_id', [$pending->id(), $unpaid->id()], 'IN')
      ->execute();
    $this->entityTypeManager->getStorage('commerce_domains_offer')->resetCache();

    $this->assertSame(2, $this->manager->expireStale());
    $this->assertSame('expired', Offer::load($pending->id())->getState()->getId());
    $this->assertSame('expired', Offer::load($unpaid->id())->getState()->getId());
    $this->assertSame('canceled', Offer::load($unpaid->id())->getOrder()->getState()->getId());
    $this->assertSame('pending', Offer::load($fresh->id())->getState()->getId());
  }

  /**
   * Tests that the buyer's page needs the offer's token.
   */
  public function testBuyerAccess(): void {
    $offer = $this->offer($this->domain('token.com'));
    $form = OfferBuyerForm::create($this->container);
    $this->assertTrue($form->access($offer, $offer->getToken())->isAllowed());
    $this->assertFalse($form->access($offer, 'guess')->isAllowed());
  }

  /**
   * Tests the offer form: the minimum, and offers it refuses.
   */
  public function testForm(): void {
    $variation = $this->domain('form.com', 2000);
    $submit = function (string $amount) use ($variation): FormStateInterface {
      $form_state = (new FormState())->setValues([
        'amount' => $amount,
        'name' => 'Ana',
        'mail' => 'ana@example.com',
        'op' => 'Make an offer',
      ]);
      $this->container->get('form_builder')->submitForm(OfferForm::class, $form_state, $variation);
      return $form_state;
    };

    $this->assertArrayHasKey('amount', $submit('900')->getErrors(), 'Offers start at half the price.');
    $this->assertSame([], $submit('1200')->getErrors());
    $offers = $this->entityTypeManager->getStorage('commerce_domains_offer')->loadByProperties(['mail' => 'ana@example.com']);
    $this->assertCount(1, $offers);
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $made */
    $made = reset($offers);
    $this->assertEquals(new Price('1200', 'USD'), $made->getAmount());

    // A domain that doesn't take offers shows no form. Both follow the
    // settings, which can change that.
    $fixed = $this->domain('firm.com', 2000, ['sale_offers' => FALSE]);
    $form = $this->container->get('form_builder')->getForm(OfferForm::class, $fixed);
    $this->assertArrayNotHasKey('amount', $form);
    $this->assertContains('config:commerce_domains_offer.settings', $form['#cache']['tags']);
    $form = $this->container->get('form_builder')->getForm(OfferForm::class, $variation);
    $this->assertContains('config:commerce_domains_offer.settings', $form['#cache']['tags']);
  }

  /**
   * Tests the admin list, the seller's and buyer's forms, and the block.
   */
  public function testPages(): void {
    $offer = $this->offer($this->domain('pages.com'));
    $build = $this->entityTypeManager->getListBuilder('commerce_domains_offer')->render();
    $list = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('pages.com', $list);
    $this->assertStringContainsString('Pending', $list);

    $form_builder = $this->container->get('form_builder');
    $admin = $form_builder->getForm(OfferAdminForm::class, $offer);
    $this->assertArrayHasKey('accept', $admin['actions']);
    $this->assertArrayHasKey('counter_amount', $admin['counter']);
    $form_state = (new FormState())->setValues(['counter_amount' => '1750', 'op' => 'Send counter-offer']);
    $form_builder->submitForm(OfferAdminForm::class, $form_state, $offer);
    $this->assertSame([], $form_state->getErrors());
    $offer = Offer::load($offer->id());
    $this->assertSame('countered', $offer->getState()->getId());
    $this->assertEquals(new Price('1750', 'USD'), $offer->getCounterAmount());
    $this->assertArrayNotHasKey('accept', $form_builder->getForm(OfferAdminForm::class, $offer)['actions'], 'The buyer answers a counter-offer.');

    $buyer = $form_builder->getForm(OfferBuyerForm::class, $offer);
    $this->assertArrayHasKey('accept', $buyer['actions']);
    $form_state = (new FormState())->setValues(['op' => (string) $buyer['actions']['accept']['#value']]);
    $form_builder->submitForm(OfferBuyerForm::class, $form_state, $offer);
    $offer = Offer::load($offer->id());
    $this->assertSame('accepted', $offer->getState()->getId());
    // Accepting goes on to pay: the order is in the anonymous buyer's session.
    $this->assertTrue($this->container->get('commerce_cart.cart_session')->hasCartId($offer->getOrder()->id()));
    $this->assertArrayHasKey('pay', $form_builder->getForm(OfferBuyerForm::class, $offer)['actions']);

    /** @var \Drupal\Core\Block\BlockPluginInterface&\Drupal\Core\Plugin\ContextAwarePluginInterface $block */
    $block = $this->container->get('plugin.manager.block')->createInstance('commerce_domains_offer_form');
    $block->setContextValue('commerce_product', $this->domain('block.com')->getProduct());
    $this->assertArrayHasKey('amount', $block->build());
    $this->assertFalse($block->access(new AnonymousUserSession()), 'Offers need the permission.');
  }

}

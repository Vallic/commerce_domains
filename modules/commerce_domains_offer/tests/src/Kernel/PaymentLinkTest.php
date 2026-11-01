<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_offer\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_owner\EventSubscriber\OfferSubscriber;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests accepted offers paid through a provider's payment link.
 */
#[CoversClass(OfferManager::class)]
#[CoversClass(OfferSubscriber::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class PaymentLinkTest extends DomainsKernelTestBase {

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
    'commerce_domains_owner',
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
    $this->installConfig([
      'system',
      'commerce_order',
      'commerce_checkout',
      'commerce_domains_offer',
      'commerce_domains_owner',
    ]);
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $this->container->get('module_handler')->loadInclude('commerce_domains_owner', 'install');
    commerce_domains_owner_install(FALSE);
    Provider::create(['id' => 'links', 'label' => 'Links', 'plugin' => 'test_payment_links'])->save();
    $this->config('commerce_domains_offer.settings')
      ->set('payment_link_provider', 'links')
      ->set('payment_link_buyer_fee', 50)
      ->save();
    $this->manager = $this->container->get(OfferManager::class);
  }

  /**
   * Returns an offer made on a new domain.
   */
  protected function offer(string $name, string $usd = '1500'): OfferInterface {
    $variations = $this->createDomain($name, 2000)->getVariations();
    $variation = reset($variations);
    assert($variation instanceof ProductVariationInterface);
    return $this->manager->submit($variation, new Price($usd, 'USD'), ['mail' => 'buyer@example.com', 'name' => 'Ana'], new AnonymousUserSession());
  }

  /**
   * Loads an offer afresh.
   */
  protected function reload(OfferInterface $offer): OfferInterface {
    $reloaded = $this->container->get('entity_type.manager')->getStorage('commerce_domains_offer')->loadUnchanged($offer->id());
    $this->assertInstanceOf(OfferInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * Sets the state the test provider reports for a link.
   */
  protected function linkState(string $id, string $state): void {
    $states = $this->container->get('state')->get('commerce_domains_test.payment_link_states', []);
    $this->container->get('state')->set('commerce_domains_test.payment_link_states', [$id => $state] + $states);
  }

  /**
   * Tests a link from accepting the offer to its payment.
   */
  public function testPaid(): void {
    $offer = $this->offer('Linked.com');
    $this->manager->accept($offer);
    $this->assertSame('accepted', $offer->getState()->getId());
    $this->assertNull($offer->getOrder(), 'Paid through the link, not checkout.');
    $this->assertSame('links', $offer->getPaymentLinkProvider()->id());
    $this->assertSame('https://pay.example.com/link-1', $offer->getPaymentLinkUrl());
    $this->assertSame('https://pay.example.com/link-1', $this->manager->checkoutUrl($offer, new AnonymousUserSession())->toString());
    $made = $this->container->get('state')->get('commerce_domains_test.payment_links')['link-1'];
    $this->assertSame('linked.com', $made['name']);
    $this->assertSame(['1500', 'USD'], $made['price']);
    $this->assertSame('buyer@example.com', $made['email']);
    $this->assertSame(['buyer_fee' => 50], $made['options']);

    // Nothing happens until the provider reports the payment.
    $this->assertSame(0, $this->manager->checkPaymentLinks());
    $this->linkState('link-1', PaymentLink::PAID);
    $this->assertSame(1, $this->manager->checkPaymentLinks());
    $offer = $this->reload($offer);
    $this->assertSame('purchased', $offer->getState()->getId());
    $product = Product::load($offer->getVariation()->getProductId());
    $this->assertFalse((bool) $product->getVariations()[0]->get('in_stock')->value, 'Off sale.');
    $this->assertSame('links', $product->get('domain_sold_via')->target_id);
    $this->assertEquals(1500, $product->get('domain_sale_price')->number);
    $this->assertNotEmpty($this->getMails(['key' => 'paid_by_link']));
  }

  /**
   * Tests a link that ends unpaid, and one the provider refuses.
   */
  public function testEndedAndRefused(): void {
    $offer = $this->offer('Ended.com');
    $this->manager->accept($offer);
    $this->linkState($offer->getPaymentLinkId(), PaymentLink::ENDED);
    $this->assertSame(1, $this->manager->checkPaymentLinks());
    $offer = $this->reload($offer);
    $this->assertSame('expired', $offer->getState()->getId());

    // A link the provider won't make leaves the offer to checkout.
    $this->container->get('state')->set('commerce_domains_test.payment_link_refuse', 'refused.com');
    $refused = $this->offer('Refused.com');
    $this->manager->accept($refused);
    $this->assertNotNull($refused->getOrder());
    $this->assertSame('', $refused->getPaymentLinkUrl());
  }

  /**
   * Tests a lease to own paid by link.
   */
  public function testLease(): void {
    $offer = $this->offer('Lease.com', '1500');
    // Each payment must reach the provider's minimum of 100.
    $this->assertNotNull($this->manager->leaseProblem($offer, 24, 10), '1350 over 24 months is under 100 a month.');
    $this->assertNull($this->manager->leaseProblem($offer, 6, 10));
    $this->assertNotNull($this->manager->leaseProblem($offer, 6, 5), 'A down payment of 75.');

    $this->manager->accept($offer, ['lease_months' => 6, 'down_payment' => 10]);
    $this->assertSame(6, $offer->getPaymentLinkLeaseMonths());
    $made = $this->container->get('state')->get('commerce_domains_test.payment_links')['link-1'];
    $this->assertSame(['buyer_fee' => 50, 'lease_months' => 6, 'down_payment' => 10], $made['options']);
    $this->linkState('link-1', PaymentLink::PAID);
    $this->manager->checkPaymentLinks();
    $product = Product::load($offer->getVariation()->getProductId());
    $this->assertSame(6, (int) $product->get('domain_lease_months')->value);

    // A lease is never left to a checkout for the full price.
    $this->container->get('state')->set('commerce_domains_test.payment_link_refuse', 'nolease.com');
    $refused = $this->offer('NoLease.com');
    try {
      $this->manager->accept($refused, ['lease_months' => 6, 'down_payment' => 10]);
      $this->fail('A lease without a link is refused.');
    }
    catch (\RuntimeException) {
    }
    $this->assertSame('pending', $this->reload($refused)->getState()->getId());
    $this->assertNull($this->reload($refused)->getOrder());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Plugin\Commerce\Condition\DomainConditionBase;
use Drupal\commerce_domains\Plugin\Commerce\Condition\OrderItemDomainAtomPremium;
use Drupal\commerce_domains\Plugin\Commerce\Condition\OrderItemDomainRegistrar;
use Drupal\commerce_domains\Plugin\Commerce\Condition\OrderItemDomainSaleTerms;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the conditions on domains, for promotions and payment gateways.
 */
#[CoversClass(DomainConditionBase::class)]
#[CoversClass(OrderItemDomainRegistrar::class)]
#[CoversClass(OrderItemDomainAtomPremium::class)]
#[CoversClass(OrderItemDomainSaleTerms::class)]
#[CoversClass(SaleTerms::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainConditionTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_number_pattern',
    'commerce_order',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_order']);
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    Provider::create(['id' => 'reg', 'label' => 'Registrar', 'plugin' => 'test_registrar'])->save();
    Provider::create(['id' => 'dynadot', 'label' => 'Dynadot', 'plugin' => 'dynadot'])->save();
    Provider::create(['id' => 'daaz', 'label' => 'DaaZ', 'plugin' => 'daaz'])->save();
  }

  /**
   * Returns an order item for a product's variation.
   */
  protected function item(ProductInterface $product): OrderItemInterface {
    return OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $product->getVariations()[0],
      'quantity' => 1,
    ]);
  }

  /**
   * Returns a condition.
   */
  protected function condition(string $id, array $configuration): DomainConditionBase {
    return $this->container->get('plugin.manager.commerce_condition')->createInstance($id, $configuration);
  }

  /**
   * Tests the registrar condition, and an order matching on any domain.
   */
  public function testRegistrar(): void {
    $reg = $this->item($this->createDomain('Reg.com', 1000, ['domain_registrar' => 'reg']));
    $dynadot = $this->item($this->createDomain('Dynadot.com', 1000, ['domain_registrar' => 'dynadot']));
    $none = $this->item($this->createDomain('None.com'));
    $condition = $this->condition('commerce_domains_order_item_registrar', ['registrars' => ['reg']]);
    $this->assertTrue($condition->evaluate($reg));
    $this->assertFalse($condition->evaluate($dynadot));
    $this->assertFalse($condition->evaluate($none));
    $this->assertSame(['config' => ['commerce_domains.provider.reg']], $condition->calculateDependencies());

    $order_condition = $this->condition('commerce_domains_order_registrar', ['registrars' => ['reg']]);
    $order = Order::create(['type' => 'default', 'order_items' => [$dynadot, $none]]);
    $this->assertFalse($order_condition->evaluate($order));
    $order->set('order_items', [$dynadot, $reg]);
    $this->assertTrue($order_condition->evaluate($order));

    // Anything but a domain doesn't match.
    $this->assertFalse($condition->evaluate(OrderItem::create(['type' => 'default', 'title' => 'Fee'])));

    // Only registrars are offered.
    $form = $condition->buildConfigurationForm(['#parents' => []], new FormState());
    $this->assertEqualsCanonicalizing(['dynadot', 'reg'], array_keys($form['registrars']['#options']));
  }

  /**
   * Tests the Atom premium condition, both ways.
   */
  public function testAtomPremium(): void {
    $premium = $this->item($this->createDomain('Premium.com', 1000, ['atom_premium' => TRUE]));
    $plain = $this->item($this->createDomain('Plain.com'));
    $condition = $this->condition('commerce_domains_order_item_atom_premium', ['premium' => TRUE]);
    $this->assertTrue($condition->evaluate($premium));
    $this->assertFalse($condition->evaluate($plain));
    $condition = $this->condition('commerce_domains_order_atom_premium', ['premium' => FALSE]);
    $this->assertTrue($condition->evaluate(Order::create(['type' => 'default', 'order_items' => [$premium, $plain]])));
    $this->assertFalse($condition->evaluate(Order::create(['type' => 'default', 'order_items' => [$premium]])));
  }

  /**
   * Tests the sale terms condition, and its form.
   */
  public function testSaleTerms(): void {
    $cheap = $this->item($this->createDomain('Cheap.com', 1000));
    $leased = $this->item($this->createDomain('Leased.com', 5000));
    $offers_only = $this->item($this->createDomain('Offers.com', 5000, [], ['sale_buy_now' => FALSE]));
    $no_lease = $this->item($this->createDomain('NoLease.com', 5000, [], ['sale_lease_months' => 0]));
    $own_lease = $this->item($this->createDomain('OwnLease.com', 1000, [], ['sale_lease_months' => 12]));

    $lease = $this->condition('commerce_domains_order_item_sale_terms', ['lease' => TRUE]);
    $this->assertFalse($lease->evaluate($cheap), 'Too cheap for the rule.');
    $this->assertTrue($lease->evaluate($leased), 'The rule, from the price.');
    $this->assertFalse($lease->evaluate($offers_only), 'No lease without buy now.');
    $this->assertFalse($lease->evaluate($no_lease));
    $this->assertTrue($lease->evaluate($own_lease), "The domain's own term.");

    $offers_only_condition = $this->condition('commerce_domains_order_item_sale_terms', [
      'buy_now' => FALSE,
      'offers' => TRUE,
    ]);
    $this->assertTrue($offers_only_condition->evaluate($offers_only));
    $this->assertFalse($offers_only_condition->evaluate($cheap));

    $form_state = new FormState();
    $form = $lease->buildConfigurationForm(['#parents' => []], $form_state);
    $this->assertSame('yes', $form['lease']['#default_value']);
    $this->assertSame('', $form['buy_now']['#default_value']);
    $form_state->setValues(['buy_now' => 'no', 'offers' => '', 'lease' => '']);
    $lease->submitConfigurationForm($form, $form_state);
    $configuration = array_intersect_key($lease->getConfiguration(), $form_state->getValues());
    $this->assertSame(['buy_now' => FALSE, 'offers' => NULL, 'lease' => NULL], $configuration);

    $form_state = (new FormState())->setValues(['buy_now' => '', 'offers' => '', 'lease' => '']);
    $lease->validateConfigurationForm($form, $form_state);
    $this->assertSame(['Choose Yes or No for at least one term.'], array_values(array_map('strval', $form_state->getErrors())));
  }

}

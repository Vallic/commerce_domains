<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_bulk\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\commerce_domains_bulk\Form\BulkFormBase;
use Drupal\commerce_domains_bulk\Form\MarketplacesForm;
use Drupal\commerce_domains_bulk\Form\PriceRulesForm;
use Drupal\commerce_domains_bulk\Form\RepriceForm;
use Drupal\commerce_domains_bulk\Form\SaleTermsForm;
use Drupal\commerce_domains_bulk\Plugin\Action\DomainBulkActionBase;
use Drupal\commerce_domains_pricing\PriceRule;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\system\Entity\Action;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use Drupal\Tests\commerce_domains_pricing\Kernel\PricingTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the bulk actions, their forms, and the edits they make.
 */
#[CoversClass(BulkEditor::class)]
#[CoversClass(DomainBulkActionBase::class)]
#[CoversClass(BulkFormBase::class)]
#[CoversClass(PriceRulesForm::class)]
#[CoversClass(RepriceForm::class)]
#[CoversClass(SaleTermsForm::class)]
#[CoversClass(MarketplacesForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class BulkActionsTest extends DomainsKernelTestBase {

  use PricingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_pricing', 'commerce_domains_bulk'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['commerce_product', 'commerce_domains_pricing', 'commerce_domains_bulk']);
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');

    // User 1 may do anything; the editor has the permissions to change
    // products and their variations.
    $this->createUser();
    $this->setUpCurrentUser([], [
      'access commerce_product overview',
      'update any default commerce_product',
      'manage default commerce_product_variation',
    ]);
  }

  /**
   * Tests the actions: who may use them, and the selection they keep.
   */
  public function testActions(): void {
    $domain = $this->createDomain('Kept.com');
    $other = $this->createDomain('Other.com');

    $actions = [
      'commerce_domains_apply_price_rules',
      'commerce_domains_reprice',
      'commerce_domains_sale_terms',
      'commerce_domains_marketplaces',
    ];
    foreach ($actions as $id) {
      $action = Action::load($id);
      $this->assertNotNull($action, $id);
      $this->assertSame('commerce_product', $action->getType());
      $this->assertNotEmpty($action->getPluginDefinition()['confirm_form_route_name']);
    }

    $action = Action::load('commerce_domains_reprice');
    $this->assertTrue($action->getPlugin()->access($domain));
    $products_only = $this->createUser(['access commerce_product overview', 'update any default commerce_product']);
    $this->assertFalse($action->getPlugin()->access($domain, $products_only), 'Without changing variations.');

    // Products that aren't domains are left out.
    ProductType::create(['id' => 'shirt', 'label' => 'Shirt', 'variationTypes' => ['default']])->save();
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'shirt', 'price' => new Price('10', 'USD')]);
    $variation->save();
    $shirt = Product::create(['type' => 'shirt', 'title' => 'Shirt', 'variations' => [$variation]]);
    $shirt->save();
    $this->assertFalse($action->getPlugin()->access($shirt));

    $action->execute([$domain, $other]);
    $this->assertSame([(int) $domain->id(), (int) $other->id()], $this->container->get('tempstore.private')->get(BulkEditor::TEMPSTORE)->get('commerce_domains_reprice'));
  }

  /**
   * Tests applying the pricing rules to a selection.
   */
  public function testPriceRules(): void {
    $this->config(PriceRules::CONFIG)->set('rules', [
      (new PriceRule(new Price('2999', 'USD'), extensions: ['com']))->toConfig(),
    ])->save();
    $com = $this->createDomain('Ruled.com', 1000);
    $org = $this->createDomain('Other.org', 1000);

    // The form shows what each domain would get.
    Action::load('commerce_domains_apply_price_rules')->execute([$com, $org]);
    $form = $this->container->get('form_builder')->getForm(PriceRulesForm::class);
    $this->assertSame('Apply the pricing rules to 2 domains?', (string) $form['#title']);
    $this->render($form);
    $this->assertText('$2,999.00');
    $this->assertText('No rule matches: kept');

    $form_state = $this->submit('commerce_domains_apply_price_rules', [$com, $org], PriceRulesForm::class);
    $this->assertSame([], $form_state->getErrors());
    $this->assertPrice('2999', 'USD', $this->variation($com)->getPrice());
    $this->assertPrice('1000', 'USD', $this->variation($org)->getPrice(), 'No rule matches: kept.');
    $this->assertNull($this->container->get('tempstore.private')->get(BulkEditor::TEMPSTORE)->get('commerce_domains_apply_price_rules'), 'The selection is done with.');
    $messages = $this->container->get('messenger')->messagesByType('status');
    $this->assertEquals(['Changed 1 domain.', '1 domain was already as asked, or had nothing to change.'], array_map('strval', $messages));
  }

  /**
   * Tests repricing by a factor, with and without rounding.
   */
  public function testReprice(): void {
    $this->setNameAcreRounding();
    $domain = $this->createDomain('Repriced.com', 1000, [], [
      'sale_minimum_offer' => new Price('500', 'USD'),
      'sale_floor_price' => new Price('800', 'USD'),
    ]);
    $plain = $this->createDomain('Plain.com', 1000);

    $this->submit('commerce_domains_reprice', [$domain, $plain], RepriceForm::class, [
      'factor' => '1.234',
      'round' => 1,
      'terms' => 1,
    ]);
    $variation = $this->variation($domain);
    // 1,234 ends in 99; offers and floor follow the factor, to the cent.
    $this->assertPrice('1299', 'USD', $variation->getPrice());
    $this->assertPrice('617', 'USD', $this->amount($variation, 'sale_minimum_offer'));
    $this->assertPrice('987.20', 'USD', $this->amount($variation, 'sale_floor_price'));
    $this->assertPrice('1299', 'USD', $this->variation($plain)->getPrice());
    $this->assertTrue($this->variation($plain)->get('sale_minimum_offer')->isEmpty(), 'Worked out from the price, so left empty.');

    // Unticked checkboxes are submitted as NULL.
    $this->submit('commerce_domains_reprice', [$domain], RepriceForm::class, [
      'factor' => '0.5',
      'round' => NULL,
      'terms' => NULL,
    ]);
    $variation = $this->variation($domain);
    $this->assertPrice('649.50', 'USD', $variation->getPrice());
    $this->assertPrice('617', 'USD', $this->amount($variation, 'sale_minimum_offer'));

    $form_state = $this->submit('commerce_domains_reprice', [$domain], RepriceForm::class, ['factor' => '0']);
    $this->assertArrayHasKey('factor', $form_state->getErrors());
  }

  /**
   * Tests setting sale terms, each left as it is or set.
   */
  public function testSaleTerms(): void {
    $domain = $this->createDomain('Terms.com', 9000, [], [
      'sale_floor_price' => new Price('8000', 'USD'),
    ]);
    $values = [
      'buy_now' => '0',
      'offers' => 'keep',
      'minimum_offer' => ['mode' => 'set', 'amount' => ['number' => '4000', 'currency_code' => 'USD']],
      'floor_price' => ['mode' => 'clear', 'amount' => ['number' => '', 'currency_code' => 'USD']],
      'lease_months' => ['mode' => 'set', 'months' => '12'],
    ];
    $this->submit('commerce_domains_sale_terms', [$domain], SaleTermsForm::class, $values);
    $variation = $this->variation($domain);
    $this->assertSame('0', (string) (int) $variation->get('sale_buy_now')->value);
    $this->assertSame('1', (string) (int) $variation->get('sale_offers')->value, 'Left as it was.');
    $this->assertPrice('4000', 'USD', $this->amount($variation, 'sale_minimum_offer'));
    $this->assertTrue($variation->get('sale_floor_price')->isEmpty());
    $this->assertSame(12, (int) $variation->get('sale_lease_months')->value);

    // The same again changes nothing.
    $this->container->get('messenger')->deleteAll();
    $this->submit('commerce_domains_sale_terms', [$domain], SaleTermsForm::class, $values);
    $this->assertEquals(['1 domain was already as asked, or had nothing to change.'], array_map('strval', $this->container->get('messenger')->messagesByType('status')));

    $form_state = $this->submit('commerce_domains_sale_terms', [$domain], SaleTermsForm::class, [
      'buy_now' => 'keep',
      'offers' => 'keep',
      'minimum_offer' => ['mode' => 'set', 'amount' => ['number' => '', 'currency_code' => 'USD']],
      'floor_price' => ['mode' => 'keep'],
      'lease_months' => ['mode' => 'keep'],
    ]);
    $this->assertArrayHasKey('minimum_offer][amount][number', $form_state->getErrors());
    $form_state = $this->submit('commerce_domains_sale_terms', [$domain], SaleTermsForm::class, [
      'buy_now' => 'keep',
      'offers' => 'keep',
      'minimum_offer' => ['mode' => 'keep'],
      'floor_price' => ['mode' => 'keep'],
      'lease_months' => ['mode' => 'keep'],
    ]);
    $this->assertArrayHasKey('buy_now', $form_state->getErrors(), 'Nothing to change.');
  }

  /**
   * Tests listing on all marketplaces, none, or all but some.
   */
  public function testMarketplaces(): void {
    Provider::create(['id' => 'daaz', 'label' => 'DaaZ', 'plugin' => 'daaz'])->save();
    Provider::create(['id' => 'market', 'label' => 'Market', 'plugin' => 'test_marketplace'])->save();
    Provider::create(['id' => 'reg', 'label' => 'Registrar', 'plugin' => 'test_registrar'])->save();
    $domain = $this->createDomain('Listed.com');

    $this->container->get('tempstore.private')->get(BulkEditor::TEMPSTORE)->set('commerce_domains_marketplaces', [(int) $domain->id()]);
    $form = $this->container->get('form_builder')->getForm(MarketplacesForm::class);
    $this->assertSame(['daaz' => 'DaaZ', 'market' => 'Market'], $form['providers']['#options'], 'Providers that list domains.');

    $this->submit('commerce_domains_marketplaces', [$domain], MarketplacesForm::class, [
      'mode' => 'except',
      'providers' => ['daaz' => 'daaz'],
    ]);
    $variation = $this->variation($domain);
    $this->assertTrue((bool) $variation->get('sale_marketplaces')->value);
    $this->assertSame(['daaz'], array_column($variation->get('sale_not_listed_on')->getValue(), 'target_id'));

    $this->submit('commerce_domains_marketplaces', [$domain], MarketplacesForm::class, ['mode' => 'none']);
    $variation = $this->variation($domain);
    $this->assertFalse((bool) $variation->get('sale_marketplaces')->value);
    $this->assertSame(['daaz'], array_column($variation->get('sale_not_listed_on')->getValue(), 'target_id'), 'Kept for when it is listed again.');

    $this->submit('commerce_domains_marketplaces', [$domain], MarketplacesForm::class, ['mode' => 'all']);
    $variation = $this->variation($domain);
    $this->assertTrue((bool) $variation->get('sale_marketplaces')->value);
    $this->assertTrue($variation->get('sale_not_listed_on')->isEmpty());

    $form_state = $this->submit('commerce_domains_marketplaces', [$domain], MarketplacesForm::class, [
      'mode' => 'except',
      'providers' => [],
    ]);
    $this->assertArrayHasKey('providers', $form_state->getErrors());
  }

  /**
   * Tests that domains the user may not change are skipped.
   */
  public function testSkipped(): void {
    $domain = $this->createDomain('Locked.com', 1000);
    $this->setUpCurrentUser([], ['access commerce_product overview']);
    $counts = $this->container->get(BulkEditor::class)->apply(BulkEditor::REPRICE, [(int) $domain->id()], [
      'factor' => '2',
      'round' => FALSE,
      'terms' => FALSE,
    ]);
    $this->assertSame(['changed' => 0, 'unchanged' => 0, 'skipped' => 1], $counts);
    $this->assertPrice('1000', 'USD', $this->variation($domain)->getPrice());
  }

  /**
   * Selects domains through an action, and submits the form it leads to.
   */
  protected function submit(string $action_id, array $products, string $form_class, array $values = []): FormStateInterface {
    Action::load($action_id)->execute($products);
    $form_state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm($form_class, $form_state);
    return $form_state;
  }

  /**
   * Returns a product's variation, freshly loaded.
   */
  protected function variation(ProductInterface $product): ProductVariationInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $storage->resetCache();
    $ids = $product->getVariationIds();
    $variation = $storage->load(reset($ids));
    $this->assertInstanceOf(ProductVariationInterface::class, $variation);
    return $variation;
  }

  /**
   * Returns an amount of a variation's sale terms.
   */
  protected function amount(ProductVariationInterface $variation, string $field): ?Price {
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem|null $item */
    $item = $variation->get($field)->first();
    return $item?->toPrice();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Form\DomainTabForm;
use Drupal\commerce_domains\Hook\ProductOperationHooks;
use Drupal\commerce_domains\Hook\ProductTabHooks;
use Drupal\commerce_domains\ProductTabs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the domain tabs with Commerce Exchanger, which reads entity types.
 */
#[CoversClass(ProductTabs::class)]
#[CoversClass(ProductTabHooks::class)]
#[CoversClass(ProductOperationHooks::class)]
#[CoversClass(\Drupal\commerce_domains_owner\Hook\ProductTabHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ProductTabsTest extends OwnerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_exchanger'];

  /**
   * Tests building entity types asks for the tabs without a service loop.
   */
  public function testEntityTypesBuild(): void {
    $this->entityTypeManager->clearCachedDefinitions();
    $definition = $this->entityTypeManager->getDefinition('commerce_product');
    foreach (['domain_registrar', 'domain_costs', 'domain_sale', 'domain_valuation'] as $operation) {
      $this->assertSame(DomainTabForm::class, $definition->getFormClass($operation), $operation);
    }
  }

  /**
   * Tests the tabs' order, and the same pages as operations.
   */
  public function testOrderAndOperations(): void {
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], [], TRUE);
    $definitions = $this->container->get('plugin.manager.menu.local_task')->getDefinitions();
    $weights = array_map(fn(string $id) => $definitions[$id]['weight'], [
      'entity.entity_tasks:entity.commerce_product.edit_form',
      'commerce_domains.product_tab:domain_costs',
      'commerce_domains.product_tab:domain_sale',
      'commerce_domains.product_tab:domain_valuation',
      'commerce_domains.product_registrar',
    ]);
    $sorted = $weights;
    sort($sorted);
    $this->assertSame($sorted, $weights, 'Edit, Costs, Sale, Valuation, Registrar.');

    $product = $this->createDomain('Ops.com');
    $operations = $this->entityTypeManager->getListBuilder('commerce_product')->getOperations($product);
    $ids = array_keys($operations);
    $expected = [
      'commerce_domains_product_tab_domain_costs',
      'commerce_domains_product_tab_domain_sale',
      'commerce_domains_product_tab_domain_valuation',
      'commerce_domains_product_registrar',
    ];
    foreach ($expected as $id) {
      $this->assertContains($id, $ids);
    }
    $this->assertSame('/product/' . $product->id() . '/sale', $operations['commerce_domains_product_tab_domain_sale']['url']->toString());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Form\DomainTabForm;
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
    foreach (['domain_details', 'domain_costs', 'domain_sale', 'domain_valuation'] as $operation) {
      $this->assertSame(DomainTabForm::class, $definition->getFormClass($operation), $operation);
    }
  }

}

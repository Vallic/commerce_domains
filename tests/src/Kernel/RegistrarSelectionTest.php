<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Plugin\EntityReferenceSelection\RegistrarSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which providers a domain's registrar can be.
 */
#[CoversClass(RegistrarSelection::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RegistrarSelectionTest extends DomainsKernelTestBase {

  /**
   * Tests that registrars with and without an API qualify, marketplaces not.
   */
  public function testRegistrars(): void {
    Provider::create(['id' => 'hover', 'label' => 'Hover'])->save();
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar'])->save();
    Provider::create(['id' => 'market', 'label' => 'Market', 'plugin' => 'test_marketplace'])->save();

    $field = $this->container->get('entity_field.manager')->getFieldDefinitions('commerce_product', 'default')['domain_registrar'];
    $handler = $this->container->get('plugin.manager.entity_reference_selection')->getSelectionHandler($field);
    $this->assertInstanceOf(RegistrarSelection::class, $handler);
    $this->assertSame(['hover' => 'Hover', 'reg' => 'Reg'], array_map('strval', $handler->getReferenceableEntities()['commerce_domains_provider']));

    $product = $this->createDomain('example.com', 1000, ['domain_registrar' => 'hover']);
    $this->assertCount(0, $product->validate());
    $product->set('domain_registrar', 'market');
    $violations = $product->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('domain_registrar.0.target_id', $violations[0]->getPropertyPath());
  }

}

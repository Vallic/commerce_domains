<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Hook\CommerceDomainsHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the hook classes build without a dependency loop.
 */
#[CoversClass(CommerceDomainsHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class HookServicesTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_exchanger'];

  /**
   * Tests building the exchanger with entity types not yet cached.
   *
   * Building it reads entity types, which runs hook_entity_type_alter(). A
   * hook class that needs the catalog would need the exchanger again, as
   * happened on deploys with cold caches.
   */
  public function testColdEntityTypes(): void {
    $this->entityTypeManager->clearCachedDefinitions();
    $this->container->get('cache.discovery')->deleteAll();
    $this->container->get('kernel')->rebuildContainer();
    // Throws a circular reference exception when the hook classes loop.
    \Drupal::service('commerce_exchanger.calculate');
    $constraints = \Drupal::entityTypeManager()->getDefinition('commerce_product')->getConstraints();
    $this->assertArrayHasKey('CommerceDomainsDomainTitle', $constraints);
  }

}

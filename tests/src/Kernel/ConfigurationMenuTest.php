<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\system\Controller\SystemController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Domains group of settings under Commerce → Configuration.
 *
 * That page only lists links that have links of their own, so the settings
 * of the module and its submodules go in one group.
 */
#[CoversNothing]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ConfigurationMenuTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['options', 'commerce_domains_owner', 'commerce_domains_pricing'];

  /**
   * Tests the group's links, and the Configuration page listing them.
   */
  public function testGroup(): void {
    $this->container->get('router.builder')->rebuild();
    $links = $this->container->get('plugin.manager.menu.link');
    $links->rebuild();
    $this->assertSame('commerce.configuration', $links->getDefinition('commerce_domains.configuration')['parent']);
    $settings = [
      'entity.commerce_domains_provider.collection',
      'commerce_domains_owner.settings',
      'commerce_domains_pricing.settings',
    ];
    foreach ($settings as $id) {
      $this->assertSame('commerce_domains.configuration', $links->getDefinition($id)['parent'], $id);
    }
    $this->assertSame('/admin/commerce/domains/settings', $links->createInstance('commerce_domains.configuration')->getUrlObject()->toString());

    $this->setUpCurrentUser([], [
      'access commerce administration pages',
      'administer commerce_domains providers',
      'administer commerce_domains pricing',
    ]);
    $build = SystemController::create($this->container)->overview('commerce.configuration');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Domains', $html);
    $this->assertStringContainsString('Domain providers', $html);
    $this->assertStringContainsString('Domain pricing', $html);
    $this->assertStringNotContainsString('Domain portfolio', $html, 'Only the links the user may open.');
  }

}

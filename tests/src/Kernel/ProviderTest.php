<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\AppraisalInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingStatsInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderPluginManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests domain provider entities, their plugins and config schema.
 */
#[CoversClass(Provider::class)]
#[CoversClass(ProviderPluginManager::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ProviderTest extends DomainsKernelTestBase {

  /**
   * Tests that every shipped plugin saves with valid config.
   *
   * Strict config schema checking fails the test on any schema gap.
   */
  public function testShippedPlugins(): void {
    $manager = $this->container->get('plugin.manager.commerce_domains_provider');
    $ids = array_keys($manager->getDefinitions());
    sort($ids);
    $marketplaces = [
      'afternic',
      'atom',
      'dynadot',
      'fruits',
      'gname',
      'namesilo',
      'spaceship',
      'test_marketplace',
      'unstoppable',
    ];
    $registrars = [
      'atom',
      'dynadot',
      'gname',
      'godaddy',
      'ionos',
      'namebright',
      'namecheap',
      'namesilo',
      'porkbun',
      'spaceship',
      'test_managed_registrar',
      'test_registrar',
      'unstoppable',
    ];
    // Providers that write the CSV file their upload page takes.
    $csv = [
      'afternic',
      'atom',
      'brandstore',
      'daaz',
      'dnx',
      'dynadot',
      'fruits',
      'nameclub',
      'sedo',
      'spaceship',
      'unstoppable',
    ];
    // Registrars whose API can also change domains.
    $managers = ['dynadot', 'namebright', 'namecheap', 'namesilo', 'porkbun', 'spaceship', 'test_managed_registrar'];
    $appraisers = ['atom', 'test_appraiser'];
    $stats = ['afternic', 'atom', 'test_marketplace'];
    $expected = array_unique([...$marketplaces, ...$registrars, ...$csv, ...$appraisers]);
    sort($expected);
    $this->assertSame($expected, $ids);
    $this->assertEqualsCanonicalizing($marketplaces, $manager->getPluginIdsWith(MarketplaceInterface::class));
    $this->assertEqualsCanonicalizing($registrars, $manager->getPluginIdsWith(RegistrarInterface::class));
    $this->assertEqualsCanonicalizing($managers, $manager->getPluginIdsWith(DomainManagementInterface::class));
    $this->assertEqualsCanonicalizing($csv, $manager->getPluginIdsWith(CsvExportInterface::class));
    $this->assertEqualsCanonicalizing($appraisers, $manager->getPluginIdsWith(AppraisalInterface::class));
    $this->assertEqualsCanonicalizing($stats, $manager->getPluginIdsWith(ListingStatsInterface::class));

    foreach ($ids as $id) {
      Provider::create(['id' => $id, 'label' => ucfirst($id), 'plugin' => $id])->save();
      $provider = Provider::load($id);
      $this->assertSame($id, $provider->getPluginId());
      $this->assertSame(in_array($id, $marketplaces, TRUE), $provider->hasCapability(MarketplaceInterface::class), $id);
      $this->assertSame(in_array($id, $registrars, TRUE), $provider->hasCapability(RegistrarInterface::class), $id);
      $this->assertSame(in_array($id, $csv, TRUE), $provider->hasCapability(CsvExportInterface::class), $id);
      $this->assertSame($provider->getPlugin()->defaultConfiguration(), $provider->getPlugin()->getConfiguration());
    }
  }

  /**
   * Tests that plugin settings are kept, and reset when the plugin changes.
   */
  public function testPluginSettings(): void {
    $provider = Provider::create(['id' => 'afternic', 'label' => 'Afternic', 'plugin' => 'afternic']);
    $provider->getPlugin()->setConfiguration(['price_factor' => 1.25] + $provider->getPlugin()->getConfiguration());
    $provider->save();
    $this->assertSame(1.25, $this->config('commerce_domains.provider.afternic')->get('configuration.price_factor'));
    $this->assertSame(1.25, Provider::load('afternic')->getPlugin()->getConfiguration()['price_factor']);

    $provider->setPluginId('spaceship');
    $this->assertSame(1.05, $provider->getPlugin()->getConfiguration()['price_factor']);
  }

  /**
   * Tests a registrar recorded by name only.
   */
  public function testProviderWithoutPlugin(): void {
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
    $provider = Provider::load('gname');
    $this->assertNull($provider->getPluginId());
    $this->assertNull($provider->getPlugin());
    $this->assertFalse($provider->hasCapability(MarketplaceInterface::class));
    $this->assertSame([], $provider->getPluginCollections());
    $this->assertNull($this->config('commerce_domains.provider.gname')->get('plugin'));
  }

  /**
   * Tests the admin list and form.
   */
  public function testAdminUi(): void {
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
    Provider::create(['id' => 'test', 'label' => 'Test', 'plugin' => 'test_marketplace', 'status' => FALSE])->save();

    $rows = $this->entityTypeManager->getListBuilder('commerce_domains_provider')->render()['table']['#rows'];
    $this->assertSame('Registrar name only', (string) $rows['gname']['capabilities']);
    $this->assertSame('', $rows['gname']['status']);
    $this->assertSame('Marketplace', (string) $rows['test']['capabilities']);
    $this->assertSame('Disabled', (string) $rows['test']['status']);

    $form_builder = $this->container->get('entity.form_builder');
    $form = $form_builder->getForm(Provider::load('test'), 'edit');
    $this->assertTrue($form['plugin']['#disabled'], 'The API can only be picked when adding.');
    $this->assertArrayHasKey('status', $form);
    $this->assertArrayHasKey('price_factor', $form['configuration']);
    $form = $form_builder->getForm(Provider::load('gname'), 'edit');
    $this->assertArrayNotHasKey('status', $form);
    $this->assertArrayNotHasKey('configuration', $form);
  }

}

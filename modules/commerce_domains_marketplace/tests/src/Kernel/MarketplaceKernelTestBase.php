<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;

/**
 * Base for marketplace sync tests, with the test marketplace provider.
 */
abstract class MarketplaceKernelTestBase extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['advancedqueue', 'commerce_domains_marketplace'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installSchema('commerce_domains_marketplace', [ListingRecorder::TABLE]);
    // The sync queue, and the views where Views is enabled.
    $this->installConfig(['commerce_domains_marketplace']);
  }

  /**
   * Creates a provider on the test marketplace.
   */
  protected function createProvider(string $id, bool $status = TRUE, bool $auto_sync = TRUE, bool $delete_unlisted = TRUE): ProviderInterface {
    $provider = Provider::create([
      'id' => $id,
      'label' => ucfirst($id),
      'plugin' => 'test_marketplace',
      'status' => $status,
    ]);
    $provider->setThirdPartySetting('commerce_domains_marketplace', 'auto_sync', $auto_sync);
    $provider->setThirdPartySetting('commerce_domains_marketplace', 'delete_unlisted', $delete_unlisted);
    $provider->save();
    return $provider;
  }

  /**
   * Sets the test marketplace's listings.
   */
  protected function setListings(array $listings): void {
    $this->container->get('state')->set('commerce_domains_test.listings', $listings);
  }

  /**
   * Returns the test marketplace's listings.
   */
  protected function listings(): array {
    return $this->container->get('state')->get('commerce_domains_test.listings', []);
  }

  /**
   * Returns the calls the test marketplace received.
   */
  protected function calls(): array {
    return $this->container->get('state')->get('commerce_domains_test.calls', []);
  }

}

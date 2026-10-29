<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_domains_marketplace_test\EventSubscriber\EventRecorder;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;

/**
 * Base for marketplace sync tests, with the test marketplace provider.
 */
abstract class MarketplaceKernelTestBase extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['advancedqueue', 'commerce_domains_marketplace', 'commerce_domains_marketplace_test'];

  // The State prefix of each test marketplace plugin.
  protected const PREFIXES = [
    'test_marketplace' => 'commerce_domains_test.',
    'test_marketplace_other' => 'commerce_domains_marketplace_test.',
  ];

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
   * Creates a provider on a test marketplace.
   */
  protected function createProvider(string $id, bool $status = TRUE, bool $auto_sync = TRUE, bool $delete_unlisted = TRUE, string $plugin = 'test_marketplace'): ProviderInterface {
    $provider = Provider::create([
      'id' => $id,
      'label' => ucfirst($id),
      'plugin' => $plugin,
      'status' => $status,
    ]);
    $provider->setThirdPartySetting('commerce_domains_marketplace', 'auto_sync', $auto_sync);
    $provider->setThirdPartySetting('commerce_domains_marketplace', 'delete_unlisted', $delete_unlisted);
    $provider->save();
    return $provider;
  }

  /**
   * Sets a test marketplace's listings.
   */
  protected function setListings(array $listings, string $plugin = 'test_marketplace'): void {
    $this->container->get('state')->set(self::PREFIXES[$plugin] . 'listings', $listings);
  }

  /**
   * Returns a test marketplace's listings.
   */
  protected function listings(string $plugin = 'test_marketplace'): array {
    return $this->container->get('state')->get(self::PREFIXES[$plugin] . 'listings', []);
  }

  /**
   * Returns the calls a test marketplace received.
   */
  protected function calls(string $plugin = 'test_marketplace'): array {
    return $this->container->get('state')->get(self::PREFIXES[$plugin] . 'calls', []);
  }

  /**
   * Ends the request the way the kernel does, and takes the queued jobs.
   *
   * @return list<\Drupal\advancedqueue\Job>
   *   The jobs, in the order they were queued; they leave the queue.
   */
  protected function takeJobs(): array {
    $this->container->get(SyncScheduler::class)->destruct();
    /** @var \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database $backend */
    $backend = Queue::load(SyncScheduler::QUEUE)->getBackend();
    $jobs = [];
    while ($job = $backend->claimJob()) {
      $jobs[] = $job;
      $backend->deleteJob($job->getId());
    }
    return $jobs;
  }

  /**
   * Processes a job the way the queue would, without retries.
   */
  protected function process(Job $job): JobResult {
    return $this->container->get('plugin.manager.advancedqueue_job_type')->createInstance($job->getType())->process($job);
  }

  /**
   * Returns a sale event the way events() lists it.
   */
  protected static function soldEvent(string $provider, string $domain, ?int $product, string $status): array {
    return [
      MarketplaceEvents::SOLD_ELSEWHERE,
      $provider,
      ['domain' => $domain, 'product' => $product, 'status' => $status],
    ];
  }

  /**
   * Returns the marketplace events dispatched so far, and forgets them.
   *
   * @return list<array{0: string, 1: string, 2: array}>
   *   Event name, provider ID and details, in order.
   */
  protected function events(): array {
    $state = $this->container->get('state');
    $events = $state->get(EventRecorder::KEY, []);
    $state->delete(EventRecorder::KEY);
    return $events;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\advancedqueue\Job;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Collects catalog changes during a request and queues one sync for them.
 *
 * Saving a product saves its variation too, and a bulk edit touches hundreds
 * of domains; queueing at the end of the request turns all of that into a
 * single job per provider, which MarketplaceSyncJob pushes in batches.
 */
class SyncScheduler implements DestructableInterface {

  // The Advanced Queue queue, shipped with the module.
  public const QUEUE = 'commerce_domains_marketplace';

  public const JOB_TYPE = 'commerce_domains_marketplace_sync';

  /**
   * Domains to re-check, as keys.
   *
   * @var array<string, true>
   */
  protected array $changed = [];

  /**
   * Domains that left the catalog, as keys.
   *
   * @var array<string, true>
   */
  protected array $removed = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Marks a domain for a sync, e.g. after a price or stock change.
   */
  public function changed(string $name): void {
    if ($name = DomainName::normalize($name)) {
      $this->changed[$name] = TRUE;
    }
  }

  /**
   * Marks a domain as gone from the catalog, so its listings are removed.
   */
  public function removed(string $name): void {
    if ($name = DomainName::normalize($name)) {
      $this->removed[$name] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    if (!$this->changed && !$this->removed) {
      return;
    }

    // Missing until update 11003 has installed Advanced Queue.
    /** @var \Drupal\advancedqueue\Entity\QueueInterface|null $queue */
    $queue = $this->entityTypeManager->hasDefinition('advancedqueue_queue')
      ? $this->entityTypeManager->getStorage('advancedqueue_queue')->load(self::QUEUE)
      : NULL;
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $queue ? $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() : [];
    $jobs = [];
    foreach ($providers as $provider) {
      if ($provider->status() && MarketplaceSettings::isAutoSync($provider) && $provider->hasCapability(MarketplaceInterface::class)) {
        $jobs[] = Job::create(self::JOB_TYPE, [
          'provider' => $provider->id(),
          'names' => array_keys($this->changed),
          'removed' => array_keys($this->removed),
        ]);
      }
    }
    if ($jobs) {
      $queue->enqueueJobs($jobs);
    }
    $this->changed = $this->removed = [];
  }

}

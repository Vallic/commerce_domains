<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\advancedqueue\Exception\DuplicateJobException;
use Drupal\advancedqueue\Job;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Collects catalog changes during a request and queues one sync for them.
 *
 * Saving a product saves its variation too, and a bulk edit touches hundreds
 * of domains; queueing at the end of the request turns all of that into a
 * single job per provider, which MarketplaceSyncJob pushes in batches.
 *
 * Cron also queues an hourly check of each of those marketplaces, as a sale
 * there changes nothing in the catalog that would queue one.
 */
class SyncScheduler implements DestructableInterface {

  // The Advanced Queue queue, shipped with the module.
  public const QUEUE = 'commerce_domains_marketplace';

  public const JOB_TYPE = 'commerce_domains_marketplace_sync';

  public const CHECK_JOB_TYPE = 'commerce_domains_marketplace_check';

  // How often cron checks a marketplace that pushes automatically.
  public const CHECK_INTERVAL = 3600;

  // The state key, plus the provider ID, of when its last check was queued.
  public const CHECKED_KEY = 'commerce_domains_marketplace.check_queued.';

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
    protected StateInterface $state,
    protected TimeInterface $time,
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

    $queue = $this->queue();
    $jobs = [];
    foreach ($queue ? $this->autoSyncProviders() : [] as $provider) {
      $jobs[] = Job::create(self::JOB_TYPE, [
        'provider' => $provider->id(),
        'names' => array_keys($this->changed),
        'removed' => array_keys($this->removed),
      ]);
    }
    if ($queue && $jobs) {
      $queue->enqueueJobs($jobs);
    }
    $this->changed = $this->removed = [];
  }

  /**
   * Queues the hourly check of each marketplace that pushes automatically.
   *
   * A check reads the whole marketplace and changes nothing there, so a sale
   * on it takes the domain off sale here within the hour. One still waiting,
   * e.g. retrying an outage, is not queued again.
   */
  public function queueChecks(): void {
    $queue = $this->queue();
    if (!$queue) {
      return;
    }
    $now = $this->time->getRequestTime();
    foreach ($this->autoSyncProviders() as $id => $provider) {
      $key = self::CHECKED_KEY . $id;
      if ($now - (int) $this->state->get($key, 0) < self::CHECK_INTERVAL) {
        continue;
      }
      try {
        $queue->enqueueJob(Job::create(self::CHECK_JOB_TYPE, ['provider' => $id]));
      }
      catch (DuplicateJobException) {
        // The last check hasn't run yet.
      }
      $this->state->set($key, $now);
    }
  }

  /**
   * Returns the module's queue.
   */
  protected function queue(): ?QueueInterface {
    // Missing until update 11003 has installed Advanced Queue.
    if (!$this->entityTypeManager->hasDefinition('advancedqueue_queue')) {
      return NULL;
    }
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(self::QUEUE);
    return $queue instanceof QueueInterface ? $queue : NULL;
  }

  /**
   * Returns the enabled marketplaces that push catalog changes automatically.
   *
   * @return array<string, \Drupal\commerce_domains\Entity\ProviderInterface>
   *   The providers, keyed by ID.
   */
  protected function autoSyncProviders(): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    return array_filter($providers, fn(ProviderInterface $provider) => $provider->status()
      && MarketplaceSettings::isAutoSync($provider)
      && $provider->hasCapability(MarketplaceInterface::class));
  }

}

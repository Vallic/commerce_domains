<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_domains_marketplace\StatsReader;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_domains\ProviderException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the views, leads and offers of a marketplace's next listings due.
 *
 * Each cron run queues one for each marketplace that reports them and has
 * listings due (see SyncScheduler::queueStats()). Reading changes nothing,
 * so a failure is retried an hour later, a few times; the next cron queues
 * another anyway.
 */
#[AdvancedQueueJobType(
  id: SyncScheduler::STATS_JOB_TYPE,
  label: new TranslatableMarkup('Marketplace listing stats'),
  max_retries: 3,
  retry_delay: 3600,
  allow_duplicates: FALSE,
)]
final class MarketplaceStatsJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected StatsReader $reader,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(StatsReader::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $provider_id = $job->getPayload()['provider'] ?? NULL;
    $provider = is_string($provider_id) ? ($this->reader->providers()[$provider_id] ?? NULL) : NULL;
    if (!$provider) {
      return JobResult::success('Skipped: the provider no longer reports listing stats.');
    }
    try {
      $names = $this->reader->readDue($provider, SyncScheduler::STATS_BATCH);
    }
    catch (ProviderException $e) {
      return JobResult::failure($e->getMessage());
    }
    return JobResult::success(sprintf('Read %d listings.', count($names)));
  }

}

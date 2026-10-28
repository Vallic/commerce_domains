<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pushes queued catalog changes to a marketplace.
 *
 * Jobs come from SyncScheduler: one provider and the domains to re-check.
 * All providers share the queue, so one that is down only delays its own
 * jobs. An outage or bad keys are retried every 15 minutes for a day; after
 * that the job is marked failed, and a full sync catches up.
 */
#[AdvancedQueueJobType(
  id: SyncScheduler::JOB_TYPE,
  label: new TranslatableMarkup('Marketplace sync'),
  max_retries: 96,
  retry_delay: 900,
)]
final class MarketplaceSyncJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MarketplaceSync $sync,
    protected LoggerInterface $logger,
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
      $container->get('entity_type.manager'),
      $container->get(MarketplaceSync::class),
      $container->get('logger.channel.commerce_domains_marketplace'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $data = $job->getPayload() + ['names' => [], 'removed' => []];
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($data['provider']);
    // Turned off since the job was queued: the next manual sync catches up.
    if (!$provider || !$provider->status() || !MarketplaceSettings::isAutoSync($provider) || !$provider->hasCapability(MarketplaceInterface::class)) {
      return JobResult::success('Skipped: the provider no longer pushes automatically.');
    }
    try {
      $errors = $this->sync->sync($provider, $data['names'], $data['removed']);
    }
    catch (ProviderException $e) {
      // Retry later; the API or the keys need time.
      if ($e->retryable) {
        $this->logger->warning('@provider sync postponed: @error', [
          '@provider' => $provider->label(),
          '@error' => $e->getMessage(),
        ]);
        return JobResult::failure($e->getMessage());
      }
      // A rejected request won't pass on retry either; a full sync, which
      // re-reads both sides, picks these domains up once the cause is fixed.
      $this->logger->error('@provider sync of @count domains failed: @error', [
        '@provider' => $provider->label(),
        '@count' => count($data['names']) + count($data['removed']),
        '@error' => $e->getMessage(),
      ]);
      return JobResult::failure($e->getMessage(), 0);
    }
    // Refused domains are on the Marketplace listings report.
    return JobResult::success($errors ? sprintf('%d of the domains refused.', count($errors)) : '');
  }

}

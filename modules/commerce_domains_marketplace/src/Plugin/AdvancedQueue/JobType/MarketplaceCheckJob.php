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
 * Checks a whole marketplace for sales, without pushing anything.
 *
 * Cron queues one an hour for each marketplace that pushes automatically
 * (see SyncScheduler::queueChecks()). A sale it finds takes the domain off
 * sale here, which queues the removal of its other listings. A check changes
 * nothing on the marketplace, so any failure is retried, every 15 minutes
 * for a day; meanwhile no other check of that marketplace is queued.
 */
#[AdvancedQueueJobType(
  id: SyncScheduler::CHECK_JOB_TYPE,
  label: new TranslatableMarkup('Marketplace sale check'),
  max_retries: 96,
  retry_delay: 900,
  allow_duplicates: FALSE,
)]
final class MarketplaceCheckJob extends JobTypeBase implements ContainerFactoryPluginInterface {

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
    $provider_id = $job->getPayload()['provider'] ?? NULL;
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = is_string($provider_id) ? $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider_id) : NULL;
    if (!$provider || !$provider->status() || !MarketplaceSettings::isAutoSync($provider) || !$provider->hasCapability(MarketplaceInterface::class)) {
      return JobResult::success('Skipped: the provider no longer pushes automatically.');
    }
    try {
      $plan = $this->sync->plan($provider);
    }
    catch (ProviderException $e) {
      $this->logger->warning('@provider sale check postponed: @error', [
        '@provider' => $provider->label(),
        '@error' => $e->getMessage(),
      ]);
      return JobResult::failure($e->getMessage());
    }
    return JobResult::success($plan->newSales ? sprintf('Sold there: %s.', implode(', ', $plan->newSales)) : '');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\QueueWorker;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pushes queued catalog changes to a marketplace.
 *
 * Items come from SyncScheduler: one provider and the domains to re-check.
 * All providers share the queue, so one that is down only delays its own
 * items.
 */
#[QueueWorker(
  id: SyncScheduler::QUEUE,
  title: new TranslatableMarkup('Marketplace sync'),
  cron: ['time' => 60],
)]
final class MarketplaceSyncWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  // Seconds before an item that hit an outage or bad keys is tried again.
  protected const RETRY_DELAY = 900;

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
  public function processItem($data): void {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($data['provider']);
    // Turned off since the item was queued: the next manual sync catches up.
    if (!$provider || !$provider->status() || !MarketplaceSettings::isAutoSync($provider) || !$provider->hasCapability(MarketplaceInterface::class)) {
      return;
    }

    try {
      $this->sync->sync($provider, $data['names'], $data['removed']);
    }
    catch (ProviderException $e) {
      // Keep the item for a later cron run; the API or the keys need time.
      if ($e->retryable) {
        $this->logger->warning('@provider sync postponed: @error', [
          '@provider' => $provider->label(),
          '@error' => $e->getMessage(),
        ]);
        throw new DelayedRequeueException(self::RETRY_DELAY, $e->getMessage(), 0, $e);
      }
      // A rejected request won't pass on retry either; a full sync, which
      // re-reads both sides, picks these domains up once the cause is fixed.
      $this->logger->error('@provider sync of @count domains failed: @error', [
        '@provider' => $provider->label(),
        '@count' => count($data['names']) + count($data['removed']),
        '@error' => $e->getMessage(),
      ]);
    }
  }

}

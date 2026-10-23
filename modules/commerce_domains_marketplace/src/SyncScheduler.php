<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Collects catalog changes during a request and queues one sync for them.
 *
 * Saving a product saves its variation too, and a bulk edit touches hundreds
 * of domains; queueing at the end of the request turns all of that into a
 * single item per provider, which the worker pushes in batches.
 */
class SyncScheduler implements DestructableInterface {

  public const QUEUE = 'commerce_domains_marketplace_sync';

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
    protected QueueFactory $queueFactory,
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

    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($providers as $provider) {
      if ($provider->status() && MarketplaceSettings::isAutoSync($provider) && $provider->hasCapability(MarketplaceInterface::class)) {
        $queue->createItem([
          'provider' => $provider->id(),
          'names' => array_keys($this->changed),
          'removed' => array_keys($this->removed),
        ]);
      }
    }
    $this->changed = $this->removed = [];
  }

}

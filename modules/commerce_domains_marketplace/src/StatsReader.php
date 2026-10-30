<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingStatsInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads the views, leads and offers of listings into their recorded rows.
 *
 * Only for listings a sync recorded; each is read once a day at most (see
 * SyncScheduler::STATS_INTERVAL).
 */
class StatsReader {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ListingRecorder $recorder,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the enabled marketplaces that report listing stats.
   *
   * @return array<string, \Drupal\commerce_domains\Entity\ProviderInterface>
   *   The providers, keyed by ID.
   */
  public function providers(): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    return array_filter($providers, fn(ProviderInterface $provider) => $provider->status()
      && $provider->hasCapability(ListingStatsInterface::class)
      && $provider->getPlugin()->getSetupError() === NULL);
  }

  /**
   * Returns the listings due a read, those never read first.
   *
   * @return string[]
   *   The domains.
   */
  public function due(ProviderInterface $provider, int $limit): array {
    return $this->recorder->statsDue((string) $provider->id(), $this->time->getRequestTime() - SyncScheduler::STATS_INTERVAL, $limit);
  }

  /**
   * Reads the next listings due, and records what the marketplace says.
   *
   * @return string[]
   *   The domains read.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function readDue(ProviderInterface $provider, int $limit): array {
    $names = $this->due($provider, $limit);
    $this->read($provider, $names);
    return $names;
  }

  /**
   * Reads some listings, and records what the marketplace says.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The marketplace.
   * @param string[] $names
   *   The domains.
   *
   * @return array<string, \Drupal\commerce_domains\ListingStats>
   *   What it reported, keyed by domain.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function read(ProviderInterface $provider, array $names): array {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof ListingStatsInterface) {
      throw new ProviderException(sprintf('%s reports no listing stats.', $provider->label()));
    }
    $stats = $names ? $plugin->fetchStats($names) : [];
    $this->recorder->recordStats((string) $provider->id(), $names, $stats);
    return $stats;
  }

}

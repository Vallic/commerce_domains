<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\ListingStats;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingStatsInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplacePluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A marketplace that keeps its listings in State.
 *
 * State keys, all under commerce_domains_test.:
 * - listings: the listings, keyed by domain. A listing's status "sold" or
 *   "pending" is a sale; "stopped" is only locked.
 * - fail: 'retryable' or 'permanent' to make every call throw.
 * - reject: domains whose save is refused.
 * - stats: views and leads keyed by domain, over 30 days.
 * - calls: what was called, for assertions.
 *
 * Subclasses keep a marketplace of their own under another STATE_PREFIX.
 */
#[CommerceDomainsProvider(
  id: 'test_marketplace',
  label: new TranslatableMarkup('Test marketplace'),
)]
class TestMarketplace extends MarketplacePluginBase implements ListingStatsInterface {

  // Where the State keys live.
  protected const STATE_PREFIX = 'commerce_domains_test.';

  // Listing statuses that are sales.
  protected const SALE_STATUSES = ['sold', 'pending'];

  /**
   * The state store holding the listings.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function manages(CatalogItem $item): bool {
    // Like Atom: premium names are left to the marketplace.
    return !$item->atomPremium;
  }

  /**
   * {@inheritdoc}
   */
  public function buildListing(CatalogItem $item): ?array {
    $price = (int) round($item->price('USD') * $this->priceFactor($item));
    return $price > 0 ? ['price' => $price] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchStats(array $names): array {
    $this->failIfAsked();
    $this->record('stats', $names);
    $stats = [];
    $reported = array_intersect_key($this->state->get(static::STATE_PREFIX . 'stats', []), array_flip($names));
    foreach ($reported as $name => [$views, $leads]) {
      $stats[$name] = new ListingStats($views, $leads, days: 30);
    }
    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    $this->failIfAsked();
    $this->record('fetch', $names);
    $listings = $this->state->get(static::STATE_PREFIX . 'listings', []);
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    $status = $current['status'] ?? NULL;
    return $status === 'sold' || $status === 'stopped' ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saleStatus(array $current): ?string {
    $status = $current['status'] ?? NULL;
    return in_array($status, static::SALE_STATUSES, TRUE) ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $this->failIfAsked();
    $this->record('save', array_keys($listings));
    $state = $this->state;
    $stored = $state->get(static::STATE_PREFIX . 'listings', []);
    $rejected = array_flip($state->get(static::STATE_PREFIX . 'reject', []));
    $errors = [];
    foreach ($listings as $name => $listing) {
      if (isset($rejected[$name])) {
        $errors[$name] = 'Rejected by the test marketplace.';
        continue;
      }
      $stored[$name] = $listing + ($current[$name] ?? []);
    }
    $state->set(static::STATE_PREFIX . 'listings', $stored);
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $this->failIfAsked();
    $this->record('delete', $names);
    $state = $this->state;
    $state->set(static::STATE_PREFIX . 'listings', array_diff_key($state->get(static::STATE_PREFIX . 'listings', []), array_flip($names)));
    return [];
  }

  /**
   * Throws when a test asked for failures.
   */
  protected function failIfAsked(): void {
    $fail = $this->state->get(static::STATE_PREFIX . 'fail');
    if ($fail) {
      throw new ProviderException("Test marketplace failed ($fail).", $fail === 'retryable');
    }
  }

  /**
   * Records a call.
   */
  protected function record(string $operation, ?array $names): void {
    $calls = $this->state->get(static::STATE_PREFIX . 'calls', []);
    $calls[] = [$operation, $names];
    $this->state->set(static::STATE_PREFIX . 'calls', $calls);
  }

}

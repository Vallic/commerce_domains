<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplacePluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A marketplace that keeps its listings in State.
 *
 * State keys, all under commerce_domains_test.:
 * - listings: the listings, keyed by domain.
 * - fail: 'retryable' or 'permanent' to make every call throw.
 * - reject: domains whose save is refused.
 * - calls: what was called, for assertions.
 */
#[CommerceDomainsProvider(
  id: 'test_marketplace',
  label: new TranslatableMarkup('Test marketplace'),
)]
final class TestMarketplace extends MarketplacePluginBase {

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
  public function fetchListings(?array $names = NULL): array {
    $this->failIfAsked();
    $this->record('fetch', $names);
    $listings = $this->state->get('commerce_domains_test.listings', []);
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    return ($current['status'] ?? NULL) === 'sold' ? 'sold' : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $this->failIfAsked();
    $this->record('save', array_keys($listings));
    $state = $this->state;
    $stored = $state->get('commerce_domains_test.listings', []);
    $rejected = array_flip($state->get('commerce_domains_test.reject', []));
    $errors = [];
    foreach ($listings as $name => $listing) {
      if (isset($rejected[$name])) {
        $errors[$name] = 'Rejected by the test marketplace.';
        continue;
      }
      $stored[$name] = $listing + ($current[$name] ?? []);
    }
    $state->set('commerce_domains_test.listings', $stored);
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $this->failIfAsked();
    $this->record('delete', $names);
    $state = $this->state;
    $state->set('commerce_domains_test.listings', array_diff_key($state->get('commerce_domains_test.listings', []), array_flip($names)));
    return [];
  }

  /**
   * Throws when a test asked for failures.
   */
  protected function failIfAsked(): void {
    $fail = $this->state->get('commerce_domains_test.fail');
    if ($fail) {
      throw new ProviderException("Test marketplace failed ($fail).", $fail === 'retryable');
    }
  }

  /**
   * Records a call.
   */
  protected function record(string $operation, ?array $names): void {
    $calls = $this->state->get('commerce_domains_test.calls', []);
    $calls[] = [$operation, $names];
    $this->state->set('commerce_domains_test.calls', $calls);
  }

}

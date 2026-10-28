<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * Brings a provider's marketplace listings in line with the catalog.
 *
 * Every sync reads both sides and sends the difference, so a failed or
 * skipped run is fixed by the next one. What each check found and each push
 * did is recorded for people, but never read back.
 */
class MarketplaceSync {

  public function __construct(
    protected Catalog $catalog,
    protected LoggerInterface $logger,
    protected ListingRecorder $recorder,
  ) {}

  /**
   * Works out what a sync would change, without changing the marketplace.
   *
   * What it found is recorded for the domain overview.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   A provider with a marketplace.
   * @param string[]|null $names
   *   The domains to check, or NULL for the catalog and the whole portfolio.
   * @param string[] $removed
   *   Domains that just left the catalog (deleted, or renamed away from). The
   *   catalog no longer knows them, so this is what lets their listings be
   *   removed rather than treated as someone else's.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function plan(ProviderInterface $provider, ?array $names = NULL, array $removed = []): SyncPlan {
    $plugin = MarketplaceSettings::plugin($provider);
    if ($names !== NULL) {
      $names = array_values(array_unique([...$names, ...$removed]));
    }

    $plan = new SyncPlan();
    $plan->full = $names === NULL;
    $items = $this->catalog->load($names, MarketplaceSettings::priceList($provider));
    $plan->current = $plugin->fetchListings($names);
    $plan->catalog = array_map(fn($item) => $item->productId, $items);

    // Domains the plugin leaves to the provider count as unknown names.
    $managed = array_filter($items, $plugin->manages(...));
    $plan->managed = array_map('strval', array_keys($managed));
    foreach ($managed as $name => $item) {
      $plan->forSale += (int) $item->forSale;
      $listed = $item->forSale && $item->terms->listsOn($provider->id());
      // Kept off this marketplace by the seller, while for sale elsewhere.
      if ($item->forSale && !$listed) {
        $plan->excluded[] = (string) $name;
      }
      $wanted = $listed ? $plugin->buildListing($item) : NULL;
      if ($wanted !== NULL) {
        $plan->wanted[$name] = $wanted;
      }
      $this->planOne($plan, $plugin, $name, $wanted);
    }
    // A whole check also removes what earlier syncs listed for products that
    // were deleted since: the record says those names were ours.
    if ($plan->full) {
      $recorded = array_flip($this->recorder->names($provider->id()));
      $removed = [...$removed, ...array_keys(array_intersect_key($recorded, $plan->current))];
    }
    $plan->removed = array_values(array_diff(array_unique($removed), array_keys($items)));
    foreach ($plan->removed as $name) {
      $this->planOne($plan, $plugin, $name, NULL);
    }

    $plan->unmanaged = array_keys(array_diff_key($plan->current, $managed, array_flip($removed)));
    $this->recorder->recordCheck($provider, $plan);
    return $plan;
  }

  /**
   * Pushes a plan to the marketplace.
   *
   * @return array<string, string>
   *   Errors keyed by domain name.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function apply(ProviderInterface $provider, SyncPlan $plan): array {
    if (!$provider->status()) {
      throw new ProviderException(sprintf('%s is disabled.', $provider->label()));
    }
    $plugin = MarketplaceSettings::plugin($provider);
    $deletes = MarketplaceSettings::deletesUnlisted($provider);

    $errors = [];
    $listings = $plan->create + $plan->update;
    $removals = $deletes ? $plan->delete : [];
    try {
      if ($listings) {
        $errors += $plugin->saveListings($listings, $plan->current);
      }
      // Saved listings stay recorded as pushed if the removals fail.
      $listings = [];
      if ($removals) {
        $errors += $plugin->deleteListings($removals);
      }
    }
    catch (ProviderException $e) {
      // What the failed call was sending is recorded as failed with it.
      $failed = array_fill_keys([...array_keys($listings), ...$removals], $e->getMessage());
      $this->recorder->recordPush($provider, $plan, $errors + $failed, $deletes);
      throw $e;
    }
    $this->recorder->recordPush($provider, $plan, $errors, $deletes);

    foreach ($errors as $name => $error) {
      $this->logger->warning('@provider rejected @domain: @error', [
        '@provider' => $provider->label(),
        '@domain' => $name,
        '@error' => $error,
      ]);
    }
    if (!$plan->isEmpty()) {
      $this->logger->info('@provider sync: @create added, @update updated, @delete removed, @failed failed.', [
        '@provider' => $provider->label(),
        '@create' => count(array_diff_key($plan->create, $errors)),
        '@update' => count(array_diff_key($plan->update, $errors)),
        '@delete' => $deletes ? count(array_diff($plan->delete, array_keys($errors))) : 0,
        '@failed' => count($errors),
      ]);
    }
    return $errors;
  }

  /**
   * Plans and pushes in one go.
   *
   * @return array<string, string>
   *   Errors keyed by domain name.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *
   * @see self::plan()
   */
  public function sync(ProviderInterface $provider, ?array $names = NULL, array $removed = []): array {
    return $this->apply($provider, $this->plan($provider, $names, $removed));
  }

  /**
   * Sorts one domain into the plan.
   *
   * @param \Drupal\commerce_domains_marketplace\SyncPlan $plan
   *   The plan being built.
   * @param \Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface $plugin
   *   The marketplace plugin.
   * @param string $name
   *   The domain.
   * @param array|null $wanted
   *   The listing the domain should have, NULL when it shouldn't be listed.
   */
  protected function planOne(SyncPlan $plan, MarketplaceInterface $plugin, string $name, ?array $wanted): void {
    $current = $plan->current[$name] ?? NULL;
    if ($current !== NULL && ($reason = $plugin->lockReason($current))) {
      $plan->locked[$name] = $reason;
    }
    elseif ($wanted === NULL) {
      if ($current !== NULL) {
        $plan->delete[] = $name;
      }
    }
    elseif ($current === NULL) {
      $plan->create[$name] = $wanted;
    }
    elseif ($changed = $plugin->changedFields($wanted, $current)) {
      $plan->update[$name] = $wanted;
      $plan->changes[$name] = $changed;
    }
    else {
      $plan->unchanged++;
    }
  }

}

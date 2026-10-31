<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\PremiumListingsInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent;
use Drupal\commerce_domains_marketplace\Event\SyncFailedEvent;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Brings a provider's marketplace listings in line with the catalog.
 *
 * Every sync reads both sides and sends the difference, so a failed or
 * skipped run is fixed by the next one. What each check found and each push
 * did is recorded for people, but never read back, except for sales: a
 * listing that shows a sale takes the domain off sale here, once.
 */
class MarketplaceSync {

  public function __construct(
    protected Catalog $catalog,
    protected LoggerInterface $logger,
    protected ListingRecorder $recorder,
    protected Availability $availability,
    protected SyncScheduler $scheduler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Works out what a sync would change, without changing the marketplace.
   *
   * What it found is recorded for the domain overview. A sale it finds is
   * acted on at once, unless this is a dry run: the domain goes off sale
   * here, which gets its other listings removed, and
   * MarketplaceEvents::SOLD_ELSEWHERE is dispatched. The selling
   * marketplace's listing is left alone. A dry run only lists the sale in
   * SyncPlan::$salesToRecord, and the next check acts on it.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   A provider with a marketplace.
   * @param string[]|null $names
   *   The domains to check, or NULL for the catalog and the whole portfolio.
   * @param string[] $removed
   *   Domains that just left the catalog (deleted, or renamed away from). The
   *   catalog no longer knows them, so this is what lets their listings be
   *   removed rather than treated as someone else's.
   * @param bool $dry_run
   *   Whether to leave the sales it finds for the next check.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function plan(ProviderInterface $provider, ?array $names = NULL, array $removed = [], bool $dry_run = FALSE): SyncPlan {
    $plugin = MarketplaceSettings::plugin($provider);
    if ($names !== NULL) {
      $names = array_values(array_unique([...$names, ...$removed]));
    }

    $plan = new SyncPlan();
    $plan->full = $names === NULL;
    $items = $this->catalog->load($names, MarketplaceSettings::priceList($provider));
    $plan->current = $plugin->fetchListings($names);
    $plan->catalog = array_map(fn($item) => $item->productId, $items);
    $plan->available = array_map(fn() => TRUE, array_filter($items, fn($item) => $item->forSale));

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

    // Recorded names still in the catalog stay unmanaged when the plugin
    // leaves them to the marketplace, or their rows would go.
    $plan->unmanaged = array_keys(array_diff_key($plan->current, $managed, array_flip($plan->removed)));
    $this->recordSales($provider, $plugin, $plan, $dry_run);
    $this->followPremium($provider, $plugin, $plan, $items, $dry_run);
    $this->recorder->recordCheck($provider, $plan);
    return $plan;
  }

  /**
   * Sets listed domains' Atom premium flag from the marketplace's listings.
   *
   * Only domains the marketplace lists change: one it doesn't list keeps
   * its flag, so a partial or failed read never turns it off. The saved
   * product queues the syncs of the marketplaces whose prices follow it.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The marketplace.
   * @param \Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface $plugin
   *   Its plugin.
   * @param \Drupal\commerce_domains_marketplace\SyncPlan $plan
   *   The check, with the current listings.
   * @param array<string, \Drupal\commerce_domains\CatalogItem> $items
   *   The catalog domains checked.
   * @param bool $dry_run
   *   TRUE to only list the changes in $plan->premium.
   */
  protected function followPremium(ProviderInterface $provider, MarketplaceInterface $plugin, SyncPlan $plan, array $items, bool $dry_run): void {
    if (!$plugin instanceof PremiumListingsInterface) {
      return;
    }
    foreach (array_intersect_key($plan->current, $items) as $name => $current) {
      $premium = $plugin->isPremium($current);
      if ($premium !== $items[$name]->atomPremium) {
        $plan->premium[(string) $name] = $premium;
      }
    }
    if ($dry_run || !$plan->premium) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    foreach ($plan->premium as $name => $premium) {
      $product = $storage->load($plan->catalog[$name]);
      if ($product instanceof ProductInterface && $product->hasField('atom_premium')) {
        $product->set('atom_premium', $premium)->save();
        $this->logger->notice('@provider lists @domain as @kind: its Atom premium flag is now @flag.', [
          '@provider' => $provider->label(),
          '@domain' => $name,
          '@kind' => $premium ? 'premium' : 'standard',
          '@flag' => $premium ? 'on' : 'off',
        ]);
      }
    }
  }

  /**
   * Acts on the sales the marketplace shows, once each.
   *
   * Only our domains count: catalog domains, even those the plugin leaves to
   * the marketplace, and domains that just left the catalog. A new sale
   * takes the domain off sale here and queues the other marketplaces' syncs,
   * which remove its listings; their next sync does without automatic
   * pushes. A dry run only lists the new sales.
   */
  protected function recordSales(ProviderInterface $provider, MarketplaceInterface $plugin, SyncPlan $plan, bool $dry_run = FALSE): void {
    $ours = $plan->catalog + array_fill_keys($plan->removed, NULL);
    foreach (array_intersect_key($plan->current, $ours) as $name => $current) {
      $status = $plugin->saleStatus($current);
      if ($status !== NULL && $status !== '') {
        $plan->sales[(string) $name] = $status;
      }
    }
    if (!$plan->sales) {
      return;
    }

    $rows = $this->recorder->rows((string) $provider->id(), array_keys($plan->sales));
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    foreach ($plan->sales as $name => $status) {
      if (!ListingRecorder::isNewSale($rows[$name] ?? NULL, $status)) {
        continue;
      }
      if ($dry_run) {
        $plan->salesToRecord[] = $name;
        continue;
      }
      $product_id = $plan->catalog[$name] ?? NULL;
      $product = $product_id ? $storage->load($product_id) : NULL;
      $product = $product instanceof ProductInterface ? $product : NULL;
      // Off sale before the sale is recorded: if this fails, the next sync
      // still finds the sale new.
      if ($product) {
        $this->availability->markProductSold($product);
      }
      unset($plan->available[$name]);
      // Another sync may have just recorded it, and acted on it.
      if (!$this->recorder->claimSale((string) $provider->id(), $name, $product_id, $status, $plan->current[$name])) {
        continue;
      }
      $plan->newSales[] = $name;
      // With other stock models the product may not change; queue anyway.
      $this->scheduler->changed($name);
      $this->logger->notice('@provider shows @domain sold (@status): it is now off sale here.', [
        '@provider' => $provider->label(),
        '@domain' => $name,
        '@status' => $status,
      ]);
      $this->eventDispatcher->dispatch(new SoldElsewhereEvent($provider, $name, $product, $status), MarketplaceEvents::SOLD_ELSEWHERE);
    }
  }

  /**
   * Tells the site that a sync failed, so the owner can hear about it.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The marketplace.
   * @param array<string, string> $errors
   *   Messages keyed by domain, or by '' for a failure of the whole run.
   */
  public function reportFailure(ProviderInterface $provider, array $errors): void {
    if ($errors) {
      $this->eventDispatcher->dispatch(new SyncFailedEvent($provider, $errors), MarketplaceEvents::SYNC_FAILED);
    }
  }

  /**
   * Pushes a plan to the marketplace.
   *
   * Domains the marketplace refuses for a new reason are reported with
   * MarketplaceEvents::SYNC_FAILED; a failure of the whole push is thrown,
   * for the caller to report once it gives up.
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
    // The same refusal as last time was reported then.
    $this->reportFailure($provider, $this->recorder->recordPush($provider, $plan, $errors, $deletes));

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
    // A listing that shows a sale is never touched, whatever the plugin locks.
    if ($current !== NULL && ($reason = $plugin->lockReason($current) ?: $plugin->saleStatus($current))) {
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

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Drush\Commands;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncPlan;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

/**
 * Drush commands for marketplace syncs.
 */
final class MarketplaceCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MarketplaceSync $sync,
  ) {
    parent::__construct();
  }

  /**
   * Pushes catalog listings to a provider's marketplace.
   *
   * Domains the marketplace shows sold are taken off sale here, before the
   * push is confirmed; a dry run only lists them. A failed sync is reported
   * with MarketplaceEvents::SYNC_FAILED.
   */
  #[CLI\Command(name: 'commerce-domains:marketplace-sync', aliases: ['cdms'])]
  #[CLI\Argument(name: 'provider', description: 'The provider ID, e.g. afternic.')]
  #[CLI\Option(name: 'domains', description: 'Comma-separated domains to sync instead of the whole catalog.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change without changing the marketplace or the catalog. Works while the provider is disabled.')]
  #[CLI\Option(name: 'details', description: 'List every domain that would change, with old and new values.')]
  #[CLI\Usage(name: 'drush cdms afternic --dry-run --details', description: 'Review a full Afternic sync.')]
  #[CLI\Usage(name: 'drush cdms afternic --domains=example.com', description: 'Push one domain.')]
  public function sync(
    string $provider,
    array $options = ['domains' => self::REQ, 'dry-run' => FALSE, 'details' => FALSE],
  ): int {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $entity */
    $entity = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider);
    if (!$entity || !$entity->hasCapability(MarketplaceInterface::class)) {
      $this->logger()->error(dt('No provider @id with a marketplace.', ['@id' => $provider]));
      return self::EXIT_FAILURE;
    }
    if ($error = $entity->getPlugin()->getSetupError()) {
      $this->logger()->error($error);
      return self::EXIT_FAILURE;
    }

    $names = $options['domains'] ? array_map(DomainName::normalize(...), StringUtils::csvToArray($options['domains'])) : NULL;
    try {
      $plan = $this->sync->plan($entity, $names, dry_run: (bool) $options['dry-run']);
    }
    catch (ProviderException $e) {
      return $this->failed($entity, $e);
    }
    $this->report($entity, $plan, $names === NULL, (bool) $options['details']);

    if ($options['dry-run'] || $plan->isEmpty()) {
      return self::EXIT_SUCCESS;
    }
    if (!$entity->status()) {
      $this->logger()->error(dt('@label is disabled; enable it before pushing.', ['@label' => $entity->label()]));
      return self::EXIT_FAILURE;
    }
    if (!$this->io()->confirm(dt('Push these changes to @label?', ['@label' => $entity->label()]))) {
      return self::EXIT_SUCCESS;
    }

    try {
      $errors = $this->sync->apply($entity, $plan);
    }
    catch (ProviderException $e) {
      return $this->failed($entity, $e);
    }
    foreach ($errors as $name => $error) {
      $this->logger()->warning("$name: $error");
    }
    if ($errors) {
      $this->logger()->error(dt('@count domains failed.', ['@count' => count($errors)]));
      return self::EXIT_FAILURE;
    }
    $this->io()->success(dt('@label is in sync.', ['@label' => $entity->label()]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints a plan.
   */
  protected function report(ProviderInterface $provider, SyncPlan $plan, bool $full, bool $details): void {
    $label = $provider->label();
    if ($full) {
      $this->io()->text(dt('@count catalog domains to list on @label; @listed listed there now.', [
        '@count' => $plan->forSale,
        '@listed' => count($plan->current),
        '@label' => $label,
      ]));
    }

    $field_counts = array_count_values(array_merge(...array_values($plan->changes)));
    arsort($field_counts);
    $updates = array_map(fn($field, $count) => "$field ×$count", array_keys($field_counts), $field_counts);
    $removal = MarketplaceSettings::deletesUnlisted($provider) ? '' : ' ' . dt('(removal is turned off; left listed)');
    // Sales are locked too; they get a line of their own.
    $other_locks = array_diff_key($plan->locked, $plan->sales);
    $locked = array_map(fn($name, $reason) => "$name $reason", array_keys($other_locks), $other_locks);
    $sales = array_map(fn($name, $status) => "$name $status", array_keys($plan->sales), $plan->sales);
    $unmanaged = dt('not in the catalog, or left to @label; never touched', ['@label' => $label]);
    $premium = array_map(fn($name, $flag) => $name . ($flag ? ' on' : ' off'), array_keys($plan->premium), $plan->premium);
    $this->io()->table([dt('Change'), dt('Domains'), dt('Notes')], [
      ...($plan->premium ? [[dt('Atom premium'), count($plan->premium), implode(', ', array_slice($premium, 0, 5))]] : []),
      [dt('Add'), count($plan->create), ''],
      [dt('Update'), count($plan->update), implode(', ', $updates)],
      [dt('Remove'), count($plan->delete), trim(implode(', ', array_slice($plan->delete, 0, 5)) . $removal)],
      [dt('Locked'), count($other_locks), implode(', ', array_slice($locked, 0, 5))],
      [dt('Sold there'), count($plan->sales), implode(', ', array_slice($sales, 0, 5))],
      [dt('Up to date'), $plan->unchanged, ''],
      [dt('Not managed'), count($plan->unmanaged), $unmanaged],
    ]);
    foreach ($plan->newSales as $name) {
      $this->logger()->warning(dt('@label shows @domain sold (@status): it is now off sale here.', [
        '@label' => $label,
        '@domain' => $name,
        '@status' => $plan->sales[$name],
      ]));
    }
    foreach ($plan->salesToRecord as $name) {
      $this->logger()->warning(dt('@label shows @domain sold (@status): a sync takes it off sale here.', [
        '@label' => $label,
        '@domain' => $name,
        '@status' => $plan->sales[$name],
      ]));
    }

    if (!$details || $plan->isEmpty()) {
      return;
    }
    $plugin = MarketplaceSettings::plugin($provider);
    $rows = [];
    $describe = fn(string $field, mixed $value) => $plugin->describeValue($field, $value);
    foreach ($plan->create as $name => $listing) {
      $values = array_map(fn($field, $value) => "$field " . $describe($field, $value), array_keys($listing), $listing);
      $rows[] = [$name, dt('add'), implode(', ', $values)];
    }
    foreach ($plan->update as $name => $listing) {
      $changes = array_map(
        fn($field) => "$field " . $describe($field, $plan->current[$name][$field] ?? NULL) . ' → ' . $describe($field, $listing[$field]),
        $plan->changes[$name],
      );
      $rows[] = [$name, dt('update'), implode(', ', $changes)];
    }
    foreach ($plan->delete as $name) {
      $rows[] = [$name, dt('remove'), ''];
    }
    $this->io()->table([dt('Domain'), dt('Change'), dt('Values')], $rows);
  }

  /**
   * Reports a sync that failed as a whole.
   */
  protected function failed(ProviderInterface $provider, ProviderException $e): int {
    $this->sync->reportFailure($provider, ['' => $e->getMessage()]);
    $this->logger()->error($e->getMessage());
    return self::EXIT_FAILURE;
  }

}

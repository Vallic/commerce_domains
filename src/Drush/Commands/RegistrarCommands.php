<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Drush\Commands;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

/**
 * Drush commands for registrar syncs.
 */
final class RegistrarCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RegistrarSync $sync,
  ) {
    parent::__construct();
  }

  /**
   * Updates the catalog's expiry dates from a registrar account.
   */
  #[CLI\Command(name: 'commerce-domains:registrar-sync', aliases: ['cdrs'])]
  #[CLI\Argument(name: 'provider', description: 'The provider ID, e.g. dynadot.')]
  #[CLI\Option(name: 'domains', description: 'Comma-separated domains to check instead of the whole account.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change without changing anything. Works while the provider is disabled.')]
  #[CLI\Option(name: 'details', description: 'List every domain that would change or needs a look.')]
  #[CLI\Option(name: 'fix-registrar', description: 'Also record this registrar on catalog domains the account holds but the catalog records elsewhere.')]
  #[CLI\Usage(name: 'drush cdrs dynadot --dry-run --details', description: 'Review what the Dynadot account would change.')]
  #[CLI\Usage(name: 'drush cdrs dynadot --fix-registrar', description: 'Update expiry dates and correct registrars.')]
  public function sync(
    string $provider,
    array $options = ['domains' => self::REQ, 'dry-run' => FALSE, 'details' => FALSE, 'fix-registrar' => FALSE],
  ): int {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $entity */
    $entity = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider);
    if (!$entity || !$entity->hasCapability(RegistrarInterface::class)) {
      $this->logger()->error(dt('No provider @id with a registrar API.', ['@id' => $provider]));
      return self::EXIT_FAILURE;
    }
    if ($error = $entity->getPlugin()->getSetupError()) {
      $this->logger()->error($error);
      return self::EXIT_FAILURE;
    }

    $fix = (bool) $options['fix-registrar'];
    $names = $options['domains'] ? array_map(DomainName::normalize(...), StringUtils::csvToArray($options['domains'])) : NULL;
    $plan = $this->sync->plan($entity, $names);
    $this->report($entity, $plan, (bool) $options['details'], $fix);

    if ($options['dry-run'] || $plan->isEmpty($fix)) {
      return self::EXIT_SUCCESS;
    }
    if (!$entity->status()) {
      $this->logger()->error(dt('@label is disabled; enable it before syncing.', ['@label' => $entity->label()]));
      return self::EXIT_FAILURE;
    }
    if (!$this->io()->confirm(dt('Update the catalog from @label?', ['@label' => $entity->label()]))) {
      return self::EXIT_SUCCESS;
    }
    $updated = $this->sync->apply($entity, $plan, $fix);
    $this->io()->success(dt('Updated @count products from @label.', ['@count' => $updated, '@label' => $entity->label()]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints a plan.
   */
  protected function report(ProviderInterface $provider, RegistrarPlan $plan, bool $details, bool $fix): void {
    $label = $provider->label();
    $this->io()->text(dt('@count domains in the @label account.', [
      '@count' => count($plan->registrations),
      '@label' => $label,
    ]));
    $elsewhere = $fix ? dt('registrar will be set to @label', ['@label' => $label]) : dt('recorded at another registrar; --fix-registrar corrects them');
    $this->io()->table([dt('Catalog domains'), dt('Count'), dt('Notes')], [
      [dt('Expiry to update'), count($plan->expires), ''],
      [dt('Up to date'), $plan->unchanged, ''],
      [dt('Not in the account'), count($plan->missing), implode(', ', array_slice($plan->missing, 0, 5))],
      [dt('In the account, recorded elsewhere'), count($plan->elsewhere), $elsewhere],
      [dt('In the account, not in the catalog'), count($plan->unknown), dt('never touched')],
    ]);
    if (!$details) {
      return;
    }

    $date = fn(?int $time) => $time === NULL ? '-' : gmdate('Y-m-d', $time);
    $rows = [];
    foreach ($plan->expires as $name => [$old, $new]) {
      $rows[] = [$name, dt('expiry'), $date($old) . ' → ' . $date($new)];
    }
    foreach ($plan->elsewhere as $name => $registrar) {
      $recorded = dt('recorded at @registrar', ['@registrar' => $registrar ?? dt('no registrar')]);
      $rows[] = [$name, $recorded, $date($plan->registrations[$name]->expires)];
    }
    foreach ($plan->missing as $name) {
      $rows[] = [$name, dt('not in the account'), ''];
    }
    if ($rows) {
      $this->io()->table([dt('Domain'), dt('Change'), dt('Expires')], $rows);
    }
  }

}

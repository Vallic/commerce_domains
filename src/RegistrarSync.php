<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings the catalog's registration details in line with a registrar.
 *
 * Reads the account and updates the expiry date of every catalog domain
 * recorded at that registrar. Nothing is written to the registrar.
 */
class RegistrarSync {

  // How often cron refreshes a provider that asked for it.
  public const INTERVAL = 86400;

  // Domains looked up in the catalog at once.
  protected const CHUNK_SIZE = 200;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Catalog $catalog,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Works out what a sync would change, without changing anything.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   A provider with a registrar API.
   * @param string[]|null $names
   *   Normalized domains to check, or NULL for the account and the catalog.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function plan(ProviderInterface $provider, ?array $names = NULL): RegistrarPlan {
    $plan = new RegistrarPlan();
    $plan->registrations = self::plugin($provider)->fetchRegistrations($names);
    $types = $this->catalog->productTypes();
    if (!$types) {
      $plan->unknown = array_keys($plan->registrations);
      return $plan;
    }

    // The products recorded at this registrar, and any product whose domain
    // the account holds.
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', $types, 'IN')->condition('domain_registrar', $provider->id());
    if ($names !== NULL) {
      $query->condition('domain', $names ?: [''], 'IN');
    }
    $ids = $query->execute();
    foreach (array_chunk(array_keys($plan->registrations), self::CHUNK_SIZE) as $chunk) {
      $ids += $storage->getQuery()->accessCheck(FALSE)->condition('type', $types, 'IN')->condition('domain', $chunk, 'IN')->execute();
    }
    ksort($ids);

    $seen = $unchanged = [];
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($storage->loadMultiple($chunk) as $product) {
        $name = (string) $product->get('domain')->value;
        $registration = $plan->registrations[$name] ?? NULL;
        $seen[$name] = TRUE;
        if ($product->get('domain_registrar')->target_id !== $provider->id()) {
          if ($registration) {
            $plan->elsewhere[$name] = $product->get('domain_registrar')->target_id ?: NULL;
            $plan->products[$name][] = (int) $product->id();
          }
          continue;
        }
        if (!$registration) {
          $plan->missing[$name] = $name;
          continue;
        }
        $expires = $product->get('domain_expires')->value;
        $expires = $expires === NULL ? NULL : (int) $expires;
        if ($registration->expires !== NULL && $expires !== $registration->expires) {
          $plan->expires[$name] = [$expires, $registration->expires];
          $plan->products[$name][] = (int) $product->id();
        }
        else {
          $unchanged[$name] = TRUE;
        }
      }
      $storage->resetCache($chunk);
    }

    // A domain with one stale listing among several still needs the update.
    $plan->unchanged = count(array_diff_key($unchanged, $plan->expires));
    $plan->missing = array_values($plan->missing);
    $plan->unknown = array_keys(array_diff_key($plan->registrations, $seen));
    return $plan;
  }

  /**
   * Writes a plan to the catalog.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The provider the plan was made for.
   * @param \Drupal\commerce_domains\RegistrarPlan $plan
   *   The plan.
   * @param bool $fix_registrar
   *   Also record this registrar on domains the account holds but the
   *   catalog records elsewhere.
   *
   * @return int
   *   How many products were updated.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function apply(ProviderInterface $provider, RegistrarPlan $plan, bool $fix_registrar = FALSE): int {
    if (!$provider->status()) {
      throw new ProviderException(sprintf('%s is disabled.', $provider->label()));
    }
    $names = array_keys($plan->expires);
    if ($fix_registrar) {
      $names = array_merge($names, array_keys($plan->elsewhere));
    }

    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $updated = 0;
    foreach ($names as $name) {
      $registration = $plan->registrations[$name];
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($storage->loadMultiple($plan->products[$name] ?? []) as $product) {
        if ($fix_registrar) {
          $product->set('domain_registrar', $provider->id());
        }
        if ($registration->expires !== NULL) {
          $product->set('domain_expires', $registration->expires);
        }
        // Registration details aren't an edit: keep the changed time.
        $product->setSyncing(TRUE);
        $product->save();
        $updated++;
      }
    }

    if ($updated) {
      $this->logger->info('@provider registrations: updated @count products.', [
        '@provider' => $provider->label(),
        '@count' => $updated,
      ]);
    }
    return $updated;
  }

  /**
   * Refreshes the providers that asked for a daily update, when due.
   *
   * A provider is retried on the next cron run after an outage, and waits a
   * day after any other failure, so bad keys don't log every hour.
   */
  public function cron(): void {
    $now = $this->time->getRequestTime();
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      if (!$provider->status() || !$provider->syncsRegistrations() || !$provider->hasCapability(RegistrarInterface::class)) {
        continue;
      }
      $key = 'commerce_domains.registration_sync.' . $provider->id();
      if ($now - (int) $this->state->get($key, 0) < self::INTERVAL) {
        continue;
      }
      try {
        $this->apply($provider, $this->plan($provider));
      }
      catch (ProviderException $e) {
        $this->logger->warning('@provider registrations not updated: @error', [
          '@provider' => $provider->label(),
          '@error' => $e->getMessage(),
        ]);
        if ($e->retryable) {
          continue;
        }
      }
      $this->state->set($key, $now);
    }
  }

  /**
   * Returns the provider's registrar plugin.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the provider has no registrar API.
   */
  public static function plugin(ProviderInterface $provider): RegistrarInterface {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof RegistrarInterface) {
      throw new ProviderException(sprintf('%s has no registrar API.', $provider->label()));
    }
    return $plugin;
  }

}

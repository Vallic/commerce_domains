<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings the catalog's registration details in line with a registrar.
 *
 * Reads the account, records the registrar on every catalog domain the
 * account holds, and updates their expiry dates, and their auto-renew setting
 * where the product keeps one (Commerce Domains Owner's costs trait). The
 * account is the record of where a domain is: one transferred in is moved to
 * this registrar on the next sync. Nothing is written to the registrar.
 */
class RegistrarSync {

  // How often cron refreshes a provider that asked for it.
  public const INTERVAL = 86400;

  // Domains looked up in the catalog at once.
  protected const CHUNK_SIZE = 200;

  // The state key, plus the provider ID, of the last read of a whole account.
  public const CHECKED_KEY = 'commerce_domains.registrations_checked.';

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
          // A sold domain leaves the account once the buyer takes it.
          if (!Availability::isSold($product)) {
            $plan->missing[$name] = $name;
          }
          continue;
        }
        $changed = FALSE;
        $expires = $product->get('domain_expires')->value;
        $expires = $expires === NULL ? NULL : (int) $expires;
        if ($registration->expires !== NULL && $expires !== $registration->expires) {
          $plan->expires[$name] = [$expires, $registration->expires];
          $changed = TRUE;
        }
        $auto_renew = self::autoRenew($product);
        if ($registration->autoRenew !== NULL && $product->hasField('domain_auto_renew') && $auto_renew !== $registration->autoRenew) {
          $plan->autoRenew[$name] = [$auto_renew, $registration->autoRenew];
          $changed = TRUE;
        }
        $registered = self::recordedTime($product, 'domain_registered');
        if ($registration->registered !== NULL && $product->hasField('domain_registered') && $registered !== $registration->registered) {
          $plan->registered[$name] = [$registered, $registration->registered];
          $changed = TRUE;
        }
        if ($changed) {
          $plan->products[$name][] = (int) $product->id();
        }
        else {
          $unchanged[$name] = TRUE;
        }
      }
      $storage->resetCache($chunk);
    }

    // A domain with one stale listing among several still needs the update.
    $plan->unchanged = count(array_diff_key($unchanged, $plan->expires, $plan->autoRenew, $plan->registered));
    $plan->missing = array_values($plan->missing);
    $plan->unknown = array_keys(array_diff_key($plan->registrations, $seen));
    if ($names === NULL) {
      $this->state->set(self::CHECKED_KEY . $provider->id(), [
        'time' => $this->time->getCurrentTime(),
        'missing' => $plan->missing,
      ]);
    }
    return $plan;
  }

  /**
   * Returns when a provider's whole account was last read.
   *
   * @return array{time: int, missing: string[]}|null
   *   The time, and the domains recorded there that the account didn't hold;
   *   NULL when the account was never read whole.
   */
  public function lastCheck(string $provider_id): ?array {
    $check = $this->state->get(self::CHECKED_KEY . $provider_id);
    return is_array($check) ? $check : NULL;
  }

  /**
   * Returns the registrar whose registration details a domain follows.
   *
   * That is the domain's registrar when its API is enabled and has read the
   * whole account, finding the domain there. Its expiry then comes from the
   * registrar rather than from edits.
   */
  public function registrationSource(ProductInterface $product): ?ProviderInterface {
    if (!$product->hasField('domain_registrar') || !$product->hasField('domain')) {
      return NULL;
    }
    $provider = $product->get('domain_registrar')->entity;
    if (!$provider instanceof ProviderInterface || !$provider->status() || !$provider->hasCapability(RegistrarInterface::class)) {
      return NULL;
    }
    $check = $this->lastCheck((string) $provider->id());
    $name = DomainName::normalize((string) $product->get('domain')->value);
    return $check && !in_array($name, $check['missing'], TRUE) ? $provider : NULL;
  }

  /**
   * Writes a plan to the catalog.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The provider the plan was made for.
   * @param \Drupal\commerce_domains\RegistrarPlan $plan
   *   The plan.
   *
   * @return int
   *   How many products were updated.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function apply(ProviderInterface $provider, RegistrarPlan $plan): int {
    if (!$provider->status()) {
      throw new ProviderException(sprintf('%s is disabled.', $provider->label()));
    }
    $names = array_unique(array_merge(array_keys($plan->expires), array_keys($plan->elsewhere), array_keys($plan->autoRenew), array_keys($plan->registered)));

    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $updated = 0;
    foreach ($names as $name) {
      $registration = $plan->registrations[$name];
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($storage->loadMultiple($plan->products[$name] ?? []) as $product) {
        $updated += (int) $this->applyRegistration($product, $provider, $registration);
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
   * Writes one registration's details to its product, when they differ.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The domain's product.
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The registrar whose account holds the domain.
   * @param \Drupal\commerce_domains\Registration $registration
   *   What the account says about it.
   *
   * @return bool
   *   Whether the product was saved.
   */
  public function applyRegistration(ProductInterface $product, ProviderInterface $provider, Registration $registration): bool {
    $values = ['domain_registrar' => $provider->id()];
    if ($registration->expires !== NULL) {
      $values['domain_expires'] = $registration->expires;
    }
    if ($registration->autoRenew !== NULL && $product->hasField('domain_auto_renew')) {
      $values['domain_auto_renew'] = (int) $registration->autoRenew;
    }
    if ($registration->registered !== NULL && $product->hasField('domain_registered')) {
      $values['domain_registered'] = $registration->registered;
    }
    $changed = FALSE;
    foreach ($values as $field => $value) {
      if ((string) $product->get($field)->getString() !== (string) $value) {
        $product->set($field, $value);
        $changed = TRUE;
      }
    }
    if (!$changed) {
      return FALSE;
    }
    // Registration details aren't an edit: keep the changed time.
    $product->setSyncing(TRUE);
    $product->save();
    return TRUE;
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
   * Returns a product's recorded date, NULL when it has none.
   */
  protected static function recordedTime(ProductInterface $product, string $field): ?int {
    if (!$product->hasField($field) || $product->get($field)->isEmpty()) {
      return NULL;
    }
    return (int) $product->get($field)->value;
  }

  /**
   * Returns a product's recorded auto-renew setting, NULL when unknown.
   */
  protected static function autoRenew(ProductInterface $product): ?bool {
    if (!$product->hasField('domain_auto_renew') || $product->get('domain_auto_renew')->isEmpty()) {
      return NULL;
    }
    return (bool) $product->get('domain_auto_renew')->value;
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

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;

/**
 * Adds the domains a registrar account holds that the catalog lacks.
 *
 * For providers set to (Add new domains from the account, beside the daily
 * registration refresh), cron does it once a day. New domains are
 * unpublished and not listed on marketplaces, so nothing is offered until
 * the owner has looked at them; they get the account's expiry date,
 * registration date and auto-renew setting where the registrar gives them,
 * and the price of the first matching pricing rule, else 0.
 */
class AccountImport {

  // The provider's third-party settings.
  public const MODULE = 'commerce_domains_import';

  // How often an account is read: once a day.
  protected const INTERVAL = 86400;

  public function __construct(
    protected DomainImporter $importer,
    protected RegistrarSync $registrarSync,
    protected Catalog $catalog,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether cron adds the provider's new domains.
   */
  public static function addsNew(ProviderInterface $provider): bool {
    return (bool) $provider->getThirdPartySetting(self::MODULE, 'add_new', FALSE);
  }

  /**
   * Adds the account's domains the catalog lacks.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The registrar.
   * @param \Drupal\commerce_domains\RegistrarPlan|null $plan
   *   The account as just read, or NULL to read it.
   * @param bool $dry_run
   *   Whether to only report what would be added.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the account can't be read.
   */
  public function addNew(ProviderInterface $provider, ?RegistrarPlan $plan = NULL, bool $dry_run = FALSE): ImportReport {
    $options = $this->options($dry_run);
    $report = new ImportReport($dry_run);
    if (!$options) {
      $report->note((string) new TranslatableMarkup('No product type has the domain trait, or there is no store: nothing can be added.'));
      return $report;
    }
    $plan ??= $this->registrarSync->plan($provider);
    if (!$plan->unknown) {
      return $report;
    }
    $rows = [];
    foreach (array_values($plan->unknown) as $index => $name) {
      $rows[] = new ImportRow($index + 1, ['domain' => $name], ['domain' => $name]);
    }
    $expires = array_map(fn($registration) => $registration->expires, $plan->registrations);
    $this->importer->import($this->importer->accountRows($rows, $provider, $expires), $options, $report);

    // What else the registrar says: when it was registered, auto-renew.
    if (!$dry_run && $report->created) {
      $storage = $this->entityTypeManager->getStorage('commerce_product');
      foreach ($storage->loadByProperties(['domain' => $report->created]) as $product) {
        $name = $product instanceof ProductInterface ? (string) $product->get('domain')->value : '';
        if ($product instanceof ProductInterface && isset($plan->registrations[$name])) {
          $this->registrarSync->applyRegistration($product, $provider, $plan->registrations[$name]);
        }
      }
    }
    return $report;
  }

  /**
   * Adds the new domains of the providers set to, once a day each.
   *
   * A provider is retried on the next cron run after an outage, and waits a
   * day after any other failure.
   */
  public function cron(): void {
    $now = $this->time->getRequestTime();
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      if (!$provider->status() || !$provider->syncsRegistrations() || !self::addsNew($provider) || !$provider->hasCapability(RegistrarInterface::class)) {
        continue;
      }
      $key = 'commerce_domains_import.account_import.' . $provider->id();
      if ($now - (int) $this->state->get($key, 0) < self::INTERVAL) {
        continue;
      }
      try {
        $report = $this->addNew($provider);
        if ($report->created) {
          $this->logger->info('Added @count domains from the @provider account, unpublished and not listed: @names.', [
            '@count' => count($report->created),
            '@provider' => $provider->label(),
            '@names' => implode(', ', $report->created),
          ]);
        }
        foreach ($report->errors as $error) {
          $this->logger->warning('@domain from the @provider account not added: @error', [
            '@domain' => $error['domain'],
            '@provider' => $provider->label(),
            '@error' => $error['message'],
          ]);
        }
      }
      catch (ProviderException $e) {
        $this->logger->warning('@provider account not read for new domains: @error', [
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
   * Returns the import options of new domains from an account.
   *
   * The first domain product type, the default store and its currency;
   * unpublished, not listed on marketplaces, priced by the pricing rules
   * or else 0.
   */
  protected function options(bool $dry_run): ?ImportOptions {
    $types = $this->catalog->productTypes();
    /** @var \Drupal\commerce_store\StoreStorageInterface $stores */
    $stores = $this->entityTypeManager->getStorage('commerce_store');
    $store = $stores->loadDefault();
    if (!$store) {
      $all = $stores->loadMultiple();
      $store = $all ? reset($all) : NULL;
    }
    if (!$types || !$store instanceof StoreInterface) {
      return NULL;
    }
    return new ImportOptions(
      productType: (string) reset($types),
      store: (int) $store->id(),
      currency: $store->getDefaultCurrencyCode() ?: 'USD',
      price: '0',
      marketplaces: FALSE,
      published: FALSE,
      dryRun: $dry_run,
      source: ImportOptions::SOURCE_REGISTRAR,
    );
  }

}

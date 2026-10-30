<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import\Drush\Commands;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\ImportOptions;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_domains_import\ImportReport;
use Drupal\commerce_domains_import\ImportRow;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing domains.
 */
final class ImportCommands extends DrushCommands {

  use AutowireTrait;

  // Rows imported between progress updates.
  protected const CHUNK_SIZE = 100;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Catalog $catalog,
    protected DomainImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports domains from a CSV file, a list or a registrar account.
   *
   * Exits with an error when any row could not be imported, so a dry run can
   * check a file before the real import.
   */
  #[CLI\Command(name: 'commerce-domains:import', aliases: ['cdi'])]
  #[CLI\Argument(name: 'file', description: 'A CSV file with a header row, or a list of domains (one per line, optionally followed by a price). - reads standard input. Leave it out with --from-registrar.')]
  #[CLI\Option(name: 'format', description: 'csv or list. Defaults to list for .txt files, csv for others.')]
  #[CLI\Option(name: 'from-registrar', description: 'Import the domains the account of this provider holds that the catalog lacks, recorded at that registrar with its expiry dates.')]
  #[CLI\Option(name: 'type', description: 'The product type of new domains. Defaults to the first with the Domain name trait.')]
  #[CLI\Option(name: 'store', description: 'The store ID of new domains. Defaults to the default store.')]
  #[CLI\Option(name: 'price', description: 'The price of new domains whose row has none (and no pricing rule matches).')]
  #[CLI\Option(name: 'currency', description: "The currency of prices that don't name one. Defaults to the store's.")]
  #[CLI\Option(name: 'buy-now', description: 'New domains sell at their price straight away; --no-buy-now makes them take offers only.')]
  #[CLI\Option(name: 'offers', description: 'New domains accept offers; --no-offers turns that off.')]
  #[CLI\Option(name: 'unpublished', description: 'Create new domains unpublished.')]
  #[CLI\Option(name: 'internal', description: 'Create new domains internal: kept for your own use, never for sale.')]
  #[CLI\Option(name: 'update', description: 'Update the price and sale terms of domains already in the catalog from their row, instead of skipping them.')]
  #[CLI\Option(name: 'lookup-registrars', description: 'For rows without a registrar, look it up in public RDAP and record the provider of that name.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what the import would do, without saving anything.')]
  #[CLI\Option(name: 'details', description: 'List every domain with what happened to it.')]
  #[CLI\Usage(name: 'drush cdi domains.csv --dry-run', description: 'Check a CSV file without importing it.')]
  #[CLI\Usage(name: 'drush cdi names.txt --price=2500 --currency=EUR --no-buy-now', description: 'Import a list as offers-only domains at 2500 EUR, unless a line gives a price.')]
  #[CLI\Usage(name: 'drush cdi --from-registrar=dynadot --unpublished', description: 'Import the Dynadot domains the catalog lacks, unpublished.')]
  public function import(
    ?string $file = NULL,
    array $options = [
      'format' => self::REQ,
      'from-registrar' => self::REQ,
      'type' => self::REQ,
      'store' => self::REQ,
      'price' => self::REQ,
      'currency' => self::REQ,
      'buy-now' => TRUE,
      'offers' => TRUE,
      'unpublished' => FALSE,
      'internal' => FALSE,
      'update' => FALSE,
      'lookup-registrars' => FALSE,
      'dry-run' => FALSE,
      'details' => FALSE,
    ],
  ): int {
    $import = $this->options($options, $file);
    if (!$import) {
      return self::EXIT_FAILURE;
    }
    if ($errors = $this->importer->validateOptions($import)) {
      foreach ($errors as $error) {
        $this->logger()->error($error);
      }
      return self::EXIT_FAILURE;
    }
    $rows = $import->source === ImportOptions::SOURCE_REGISTRAR
      ? $this->accountRows((string) $options['from-registrar'], $import->dryRun)
      : $this->fileRows($file, $import->source);
    if ($rows === NULL) {
      return self::EXIT_FAILURE;
    }

    $report = new ImportReport($import->dryRun);
    $chunks = array_chunk($rows, self::CHUNK_SIZE);
    if (count($chunks) > 1) {
      $this->io()->progressStart(count($rows));
    }
    foreach ($chunks as $chunk) {
      $this->importer->import($chunk, $import, $report);
      if (count($chunks) > 1) {
        $this->io()->progressAdvance(count($chunk));
      }
    }
    if (count($chunks) > 1) {
      $this->io()->progressFinish();
    }

    $this->report($report, (bool) $options['details']);
    return $report->errors ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Reads the import's choices from the options, with their defaults.
   */
  protected function options(array $options, ?string $file): ?ImportOptions {
    // A list or CSV as told, else by the file's name: .txt files hold lists.
    $source = $file !== NULL && str_ends_with(strtolower($file), '.txt') ? ImportOptions::SOURCE_LIST : ImportOptions::SOURCE_CSV;
    if ($options['from-registrar']) {
      $source = ImportOptions::SOURCE_REGISTRAR;
    }
    elseif ($options['format']) {
      if (!in_array($options['format'], [ImportOptions::SOURCE_CSV, ImportOptions::SOURCE_LIST], TRUE)) {
        $this->logger()->error(dt('The format is csv or list, not @format.', ['@format' => $options['format']]));
        return NULL;
      }
      $source = $options['format'];
    }

    $types = $this->catalog->productTypes();
    /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');
    /** @var \Drupal\commerce_store\Entity\StoreInterface|null $store */
    $store = $options['store'] ? $store_storage->load($options['store']) : $store_storage->loadDefault();
    if (!$store) {
      $this->logger()->error($options['store'] ? dt('There is no store @id.', ['@id' => $options['store']]) : dt('There is no default store: choose one with --store.'));
      return NULL;
    }
    return new ImportOptions(
      productType: (string) ($options['type'] ?: reset($types)),
      store: (int) $store->id(),
      currency: strtoupper((string) ($options['currency'] ?: $store->getDefaultCurrencyCode())),
      price: $options['price'] === NULL || $options['price'] === '' ? NULL : (string) $options['price'],
      buyNow: (bool) $options['buy-now'],
      offers: (bool) $options['offers'],
      published: !$options['unpublished'],
      internal: (bool) ($options['internal'] ?? FALSE),
      existing: $options['update'] ? ImportOptions::UPDATE : ImportOptions::SKIP,
      lookupRegistrars: (bool) $options['lookup-registrars'],
      dryRun: (bool) $options['dry-run'],
      source: $source,
    );
  }

  /**
   * Reads the rows of a file, or of standard input.
   *
   * @return \Drupal\commerce_domains_import\ImportRow[]|null
   *   The rows, or NULL after logging why there are none.
   */
  protected function fileRows(?string $file, string $source): ?array {
    if ($file === NULL || $file === '') {
      $this->logger()->error(dt('Name a file to import, or a registrar with --from-registrar.'));
      return NULL;
    }
    $content = $file === '-' ? stream_get_contents(STDIN) : (is_file($file) && is_readable($file) ? file_get_contents($file) : FALSE);
    if ($content === FALSE) {
      $this->logger()->error(dt('Cannot read @file.', ['@file' => $file]));
      return NULL;
    }
    try {
      $rows = $source === ImportOptions::SOURCE_LIST ? ImportParser::parseList($content) : ImportParser::parseCsv($content);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return NULL;
    }
    if (!$rows) {
      $this->logger()->error(dt('@file has no domains.', ['@file' => $file]));
      return NULL;
    }
    return $rows;
  }

  /**
   * Reads the domains a registrar account holds that the catalog lacks.
   *
   * @return \Drupal\commerce_domains_import\ImportRow[]|null
   *   The rows, or NULL after logging why there are none.
   */
  protected function accountRows(string $provider_id, bool $dry_run): ?array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider_id);
    if (!$provider || !$provider->hasCapability(RegistrarInterface::class)) {
      $this->logger()->error(dt('No provider @id with a registrar API.', ['@id' => $provider_id]));
      return NULL;
    }
    // Like a registrar sync, a dry run works while the provider is disabled.
    if (!$provider->status() && !$dry_run) {
      $this->logger()->error(dt('@label is disabled; enable it before importing from it.', ['@label' => $provider->label()]));
      return NULL;
    }
    try {
      $plan = $this->importer->readAccount($provider);
    }
    catch (ProviderException $e) {
      $this->logger()->error(dt('@label could not be read: @error', [
        '@label' => $provider->label(),
        '@error' => $e->getMessage(),
      ]));
      return NULL;
    }
    $this->io()->text(dt('@count domains in the @label account; @unknown not in the catalog.', [
      '@count' => count($plan->registrations),
      '@label' => $provider->label(),
      '@unknown' => count($plan->unknown),
    ]));
    $rows = array_map(fn(string $name) => new ImportRow(0, ['domain' => $name], ['domain' => $name]), $plan->unknown);
    $expires = array_map(fn(Registration $registration) => $registration->expires, $plan->registrations);
    return $this->importer->accountRows($rows, $provider, $expires);
  }

  /**
   * Prints a report.
   */
  protected function report(ImportReport $report, bool $details): void {
    if ($report->dryRun) {
      $this->io()->note(dt('Dry run: nothing was saved.'));
    }
    $this->io()->table([dt('Domains'), dt('Count')], [
      [$report->dryRun ? dt('To create') : dt('Created'), count($report->created)],
      [$report->dryRun ? dt('To update') : dt('Updated'), count($report->updated)],
      [dt('Skipped'), count($report->skipped)],
      [dt('Errors'), count($report->errors)],
    ]);
    if ($report->errors) {
      $rows = array_map(fn(array $error) => [$error['line'] ?: '', $error['domain'], $error['message']], $report->errors);
      $this->io()->table([dt('Line'), dt('Domain'), dt('Error')], $rows);
    }
    foreach ($report->notes as $message => $count) {
      $this->io()->warning($count > 1 ? dt('@message (@count domains)', ['@message' => $message, '@count' => $count]) : $message);
    }
    if (!$details) {
      return;
    }
    $rows = [];
    foreach ($report->created as $name) {
      $rows[] = [$name, $report->dryRun ? dt('to create') : dt('created')];
    }
    foreach ($report->updated as $name) {
      $rows[] = [$name, $report->dryRun ? dt('to update') : dt('updated')];
    }
    foreach ($report->skipped as $name => $reason) {
      $rows[] = [$name, dt('skipped: @reason', ['@reason' => $reason])];
    }
    if ($rows) {
      $this->io()->table([dt('Domain'), dt('Result')], $rows);
    }
  }

}

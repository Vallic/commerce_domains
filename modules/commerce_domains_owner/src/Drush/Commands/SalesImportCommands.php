<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Drush\Commands;

use Drupal\commerce_domains_owner\SalesImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush command for importing domain sales from a CSV file.
 */
final class SalesImportCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected SalesImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports domain sales from a CSV file: price, commission and where.
   *
   * Columns: domain (required), sold, price, currency, commission (percent),
   * received, sold_via (a provider ID or name) and lease_months. A domain not
   * sold yet needs the date and the price, and goes off sale; for one sold,
   * the row's values replace what it has. Sales on this site are left out.
   */
  #[CLI\Command(name: 'commerce-domains:sales-import', aliases: ['cdsimport'])]
  #[CLI\Argument(name: 'file', description: 'The CSV file, or - for standard input.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change, and change nothing.')]
  #[CLI\Usage(name: 'drush cdsimport sales.csv --dry-run', description: 'Show what sales.csv would change.')]
  public function import(string $file, array $options = ['dry-run' => FALSE]): int {
    $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
    if ($content === FALSE) {
      $this->logger()->error(dt('Cannot read @file.', ['@file' => $file]));
      return self::EXIT_FAILURE;
    }
    try {
      $plan = $this->importer->plan(SalesImporter::parse($content));
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $rows = [];
    foreach ($plan as $line => $entry) {
      $rows[] = [
        $line,
        $entry['domain'],
        match (TRUE) {
          $entry['problem'] !== NULL => dt('Left out: @problem', ['@problem' => $entry['problem']]),
          !$entry['changes'] => dt('Nothing changes.'),
          default => ($entry['new'] ? dt('New sale') . '; ' : '') . implode('; ', $this->importer->describe($entry['changes'])),
        },
      ];
    }
    $this->io()->table([dt('Line'), dt('Domain'), $options['dry-run'] ? dt('Would change') : dt('Changes')], $rows);
    if (!$options['dry-run']) {
      $count = $this->importer->apply($plan);
      $this->io()->text(dt('Imported the sales of @count domains.', ['@count' => $count]));
    }
    return self::EXIT_SUCCESS;
  }

}

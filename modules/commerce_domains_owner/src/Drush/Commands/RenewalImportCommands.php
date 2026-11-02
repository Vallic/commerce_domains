<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Drush\Commands;

use Drupal\commerce_domains_owner\RenewalImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush command for importing the renewals paid from a CSV file.
 */
final class RenewalImportCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected RenewalImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports the renewals paid from a CSV file or Dynadot's order history.
   *
   * Columns: domain (required), date, amount, currency, years, type, registrar
   * (a provider ID or name) and reference. A file with a type column keeps
   * only its renewals, transfers and restores. Rows imported before add
   * nothing, so a file can be imported again as it grows.
   */
  #[CLI\Command(name: 'commerce-domains:renewals-import', aliases: ['cdrenewals'])]
  #[CLI\Argument(name: 'file', description: 'The CSV file, or - for standard input.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would be added, and add nothing.')]
  #[CLI\Usage(name: 'drush cdrenewals dynadot_all.csv --dry-run', description: 'Show what the Dynadot order history would add.')]
  public function import(string $file, array $options = ['dry-run' => FALSE]): int {
    $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
    if ($content === FALSE) {
      $this->logger()->error(dt('Cannot read @file.', ['@file' => $file]));
      return self::EXIT_FAILURE;
    }
    try {
      $plan = $this->importer->plan(RenewalImporter::parse($content));
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $problems = [];
    foreach ($plan as $line => $entry) {
      if ($entry['problem'] !== NULL) {
        $problems[] = [$line, $entry['domain'], $entry['problem']];
      }
    }
    if ($problems) {
      $this->io()->table([dt('Line'), dt('Domain'), dt('Left out')], $problems);
    }
    $summary = RenewalImporter::summary($plan);
    $this->io()->text(dt('@new to add, @known imported before, @problems left out, @passed not renewals.', [
      '@new' => $summary['new'],
      '@known' => $summary['known'],
      '@problems' => $summary['problems'],
      '@passed' => $summary['passed'],
    ]));
    if (!$options['dry-run']) {
      $this->io()->text(dt('Imported @count renewals.', ['@count' => $this->importer->apply($plan)]));
    }
    return self::EXIT_SUCCESS;
  }

}

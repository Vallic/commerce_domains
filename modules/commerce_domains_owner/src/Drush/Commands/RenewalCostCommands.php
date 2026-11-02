<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Drush\Commands;

use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\RenewalCostImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush command for the table of renewal costs.
 */
final class RenewalCostCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected RenewalCostImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Fills the renewal costs from a CSV file or a price list.
   *
   * A file has the columns extension, renewal, currency and registrar, and
   * replaces the costs set. --from names a provider that reports its prices,
   * or porkbun_public (the default), Porkbun's public prices, which fill the
   * gaps in the general costs unless told to --replace them.
   */
  #[CLI\Command(name: 'commerce-domains:renewal-costs', aliases: ['cdrenew'])]
  #[CLI\Argument(name: 'file', description: 'A CSV file. Leave it out to read a price list.')]
  #[CLI\Option(name: 'from', description: 'The price list: a provider ID, or porkbun_public.')]
  #[CLI\Option(name: 'replace', description: "Replace the general costs set, with Porkbun's public prices.")]
  #[CLI\Option(name: 'dry-run', description: 'Show the costs, and save nothing.')]
  #[CLI\Usage(name: 'drush cdrenew --from=dynadot --dry-run', description: "Show this account's Dynadot prices.")]
  #[CLI\Usage(name: 'drush cdrenew costs.csv', description: 'Import the costs in a file.')]
  public function fill(
    ?string $file = NULL,
    array $options = ['from' => RenewalCostImporter::PORKBUN_LIST, 'replace' => FALSE, 'dry-run' => FALSE],
  ): int {
    $unpriced = [];
    try {
      if ($file !== NULL) {
        $content = is_readable($file) ? file_get_contents($file) : FALSE;
        if ($content === FALSE) {
          $this->logger()->error(dt('Cannot read @file.', ['@file' => $file]));
          return self::EXIT_FAILURE;
        }
        $plan = $this->importer->planRows(RenewalCostImporter::parse($content));
      }
      else {
        $list = $this->importer->planList((string) $options['from'], (bool) $options['replace']);
        $plan = $list['plan'];
        $unpriced = $list['unpriced'];
      }
    }
    catch (ProviderException | \InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $rows = [];
    foreach ($plan as $line => $entry) {
      if (in_array($entry['status'], ['new', 'replaced'], TRUE)) {
        $price = $entry['price'];
        $registrar = (string) $entry['registrar'] ?: dt('any');
        $rows[] = [$entry['tld'], $registrar, $price->getCurrencyCode() . ' ' . $price->getNumber(), $entry['status']];
      }
      elseif ($entry['status'] === 'problem') {
        $problem = dt('line @line: @problem', ['@line' => $line, '@problem' => $entry['problem']]);
        $rows[] = [$entry['tld'], '', '-', $problem];
      }
    }
    foreach ($unpriced as $tld) {
      $rows[] = [$tld, '', '-', dt('not on the list')];
    }
    if ($rows) {
      $this->io()->table([dt('Extension'), dt('Registrar'), dt('Cost a year'), ''], $rows);
    }
    $summary = RenewalCostImporter::summary($plan);
    if (!$summary['new'] && !$summary['replaced']) {
      $this->io()->text(dt('Nothing to save.'));
    }
    elseif (!$options['dry-run']) {
      $this->io()->text(dt('Saved @count renewal costs.', ['@count' => $this->importer->apply($plan)]));
    }
    return self::EXIT_SUCCESS;
  }

}

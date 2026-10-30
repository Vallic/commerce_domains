<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Drush\Commands;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\StatsReader;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

/**
 * Drush commands for listing views, leads and offers.
 */
final class StatsCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected StatsReader $reader,
  ) {
    parent::__construct();
  }

  /**
   * Reads listing views, leads and offers from a marketplace now.
   *
   * Cron reads each listing once a day; this reads the next ones due, or the
   * domains given, without waiting. Only listings a sync recorded are read.
   */
  #[CLI\Command(name: 'commerce-domains:marketplace-stats', aliases: ['cdmst'])]
  #[CLI\Argument(name: 'provider', description: 'The provider ID, e.g. afternic.')]
  #[CLI\Option(name: 'domains', description: 'Comma-separated domains to read instead of the next ones due.')]
  #[CLI\Option(name: 'limit', description: 'How many of the listings due to read.')]
  #[CLI\Usage(name: 'drush cdmst afternic --limit=100', description: 'Read the next 100 Afternic listings due.')]
  public function stats(string $provider, array $options = ['domains' => self::REQ, 'limit' => 60]): int {
    $entity = $this->reader->providers()[$provider] ?? NULL;
    if (!$entity) {
      $this->logger()->error(dt('No enabled provider @id that reports listing stats and is set up.', ['@id' => $provider]));
      return self::EXIT_FAILURE;
    }
    $names = $options['domains']
      ? array_map(DomainName::normalize(...), StringUtils::csvToArray($options['domains']))
      : $this->reader->due($entity, max(1, (int) $options['limit']));
    try {
      $stats = $this->reader->read($entity, $names);
    }
    catch (ProviderException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $rows = [];
    foreach ($names as $name) {
      $found = $stats[$name] ?? NULL;
      if (!$found) {
        $rows[] = [$name, '-', '-', '-', '-', dt('not listed there')];
        continue;
      }
      $counts = [$found->views, $found->leads, $found->offers, $found->saves];
      $counts = array_map(fn(?int $value) => $value ?? '-', $counts);
      $rows[] = [$name, ...$counts, $found->days ? dt('@days days', ['@days' => $found->days]) : dt('all time')];
    }
    if ($rows) {
      $this->io()->table([dt('Domain'), dt('Views'), dt('Leads'), dt('Offers'), dt('Saves'), dt('Over')], $rows);
    }
    else {
      $this->io()->text(dt('No listings are due a read.'));
    }
    return self::EXIT_SUCCESS;
  }

}

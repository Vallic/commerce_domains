<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Drush\Commands;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\MarketplaceSales;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

/**
 * Drush commands for the sales marketplaces report.
 */
final class MarketplaceSalesCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected MarketplaceSales $sales,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct();
  }

  /**
   * Reads a marketplace's sales report, and fills in the domains' sales.
   *
   * Cron does this once a day. The sale date replaces when the sale was
   * found; what it brought after commission and the lease payments are only
   * filled in when empty. Sales on this site or another marketplace stand.
   */
  #[CLI\Command(name: 'commerce-domains:marketplace-sales', aliases: ['cdsales'])]
  #[CLI\Argument(name: 'provider', description: 'The provider ID, e.g. atom.')]
  #[CLI\Option(name: 'domains', description: 'Comma-separated domains to read instead of every sale.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would be filled in, and change nothing.')]
  #[CLI\Usage(name: 'drush cdsales atom --dry-run', description: 'Show the Atom sales and what they would fill in.')]
  public function sales(string $provider, array $options = ['domains' => self::REQ, 'dry-run' => FALSE]): int {
    $entity = $this->sales->providers()[$provider] ?? NULL;
    if (!$entity) {
      $this->logger()->error(dt('No enabled provider @id that reports sales and is set up.', ['@id' => $provider]));
      return self::EXIT_FAILURE;
    }
    $names = $options['domains'] ? array_map(DomainName::normalize(...), StringUtils::csvToArray($options['domains'])) : NULL;
    try {
      $plan = $this->sales->plan($entity, $names);
    }
    catch (ProviderException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $money = fn(?float $amount, string $currency) => $amount === NULL ? '-' : $currency . ' ' . number_format($amount, 2);
    $rows = [];
    foreach ($plan as $name => $entry) {
      $sale = $entry['sale'];
      $changes = [];
      foreach ($entry['changes'] as $field => $value) {
        $changes[$field] = match (TRUE) {
          $value instanceof Price => $value->getCurrencyCode() . ' ' . $value->getNumber(),
          $field === 'domain_sold' => $this->dateFormatter->format((int) $value, 'custom', 'Y-m-d'),
          default => (string) $value,
        };
      }
      $rows[] = [
        $name,
        $sale->sold ? $this->dateFormatter->format($sale->sold, 'custom', 'Y-m-d H:i') : '-',
        $money($sale->price, $sale->currency),
        $money($sale->received, $sale->currency),
        $sale->leaseMonths ?? '-',
        $sale->status ?? '-',
        match (TRUE) {
          !$entry['product'] => dt('not in the catalog'),
          !$changes => dt('nothing to fill in'),
          default => implode(', ', array_map(fn($field, $value) => "$field: $value", array_keys($changes), $changes)),
        },
      ];
    }
    if (!$rows) {
      $this->io()->text(dt('No sales reported.'));
      return self::EXIT_SUCCESS;
    }
    $header = [dt('Domain'), dt('Sold'), dt('Price'), dt('Received'), dt('Lease'), dt('Transfer')];
    $header[] = $options['dry-run'] ? dt('Would fill in') : dt('Filled in');
    $this->io()->table($header, $rows);
    if (!$options['dry-run']) {
      $count = $this->sales->apply($entity, $plan);
      $this->io()->text(dt('Filled in the sale of @count domains.', ['@count' => $count]));
    }
    return self::EXIT_SUCCESS;
  }

}

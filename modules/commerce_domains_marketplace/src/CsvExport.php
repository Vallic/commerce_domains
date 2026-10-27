<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains\ProviderException;

/**
 * Writes the CSV files marketplaces import, from the catalog.
 *
 * The domains that are for sale, priced like a sync: with the provider's
 * price list and price factor.
 */
class CsvExport {

  public function __construct(
    protected Catalog $catalog,
  ) {}

  /**
   * Returns a provider's CSV plugin.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the provider exports no CSV files.
   */
  public static function plugin(ProviderInterface $provider): CsvExportInterface {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof CsvExportInterface) {
      throw new ProviderException(sprintf('%s has no CSV export.', $provider->label()));
    }
    return $plugin;
  }

  /**
   * Returns the rows of a provider's files, each with its header first.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The provider.
   * @param string|null $file
   *   One of the plugin's csvFiles(), or NULL for all of them.
   *
   * @return array<string, array<int, array>>
   *   The rows, keyed by file ID.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the provider has no such file.
   */
  public function rows(ProviderInterface $provider, ?string $file = NULL): array {
    $plugin = self::plugin($provider);
    $files = array_keys($plugin->csvFiles());
    if ($file !== NULL) {
      if (!in_array($file, $files, TRUE)) {
        throw new ProviderException(sprintf('%s has no CSV file %s.', $provider->label(), $file));
      }
      $files = [$file];
    }
    $rows = [];
    foreach ($files as $id) {
      $rows[$id] = [$plugin->csvHeader($id)];
    }
    foreach ($this->catalog->load(NULL, MarketplaceSettings::priceList($provider)) as $item) {
      if (!$item->forSale) {
        continue;
      }
      foreach ($files as $id) {
        if (($row = $plugin->csvRow($item, $id)) !== NULL) {
          $rows[$id][] = $row;
        }
      }
    }
    return $rows;
  }

  /**
   * Encodes rows as CSV.
   */
  public static function encode(array $rows): string {
    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
      fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
  }

  /**
   * Returns the download's name, e.g. "sedo.csv" or "dynadot-external.csv".
   */
  public static function filename(ProviderInterface $provider, string $file): string {
    $files = self::plugin($provider)->csvFiles();
    return $provider->id() . (count($files) > 1 && $file !== array_key_first($files) ? '-' . $file : '') . '.csv';
  }

}

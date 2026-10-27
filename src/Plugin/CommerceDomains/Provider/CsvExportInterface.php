<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\CatalogItem;

/**
 * Capability: writes listings in the CSV file a marketplace imports.
 *
 * For marketplaces without an API, and for sellers who upload a file to one
 * that has an API. Rows follow the same pricing rules as an API listing, and
 * only domains that are for sale are exported.
 */
interface CsvExportInterface extends ProviderPluginInterface {

  /**
   * Returns the CSV files the provider imports.
   *
   * @return array<string, string>
   *   Labels keyed by file ID. Most providers take one file, "listings".
   */
  public function csvFiles(): array;

  /**
   * Returns a file's header row.
   *
   * @param string $file
   *   The file ID.
   *
   * @return string[]
   *   The column names, as the provider's template has them.
   */
  public function csvHeader(string $file): array;

  /**
   * Returns a domain's row in a file.
   *
   * @param \Drupal\commerce_domains\CatalogItem $item
   *   A domain that is for sale.
   * @param string $file
   *   The file ID.
   *
   * @return array<int, string|int|float>|null
   *   The cells, in header order, or NULL to leave the domain out.
   */
  public function csvRow(CatalogItem $item, string $file): ?array;

}

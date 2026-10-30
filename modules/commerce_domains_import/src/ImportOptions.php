<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

/**
 * The choices an import runs with, from the import form or Drush.
 */
final class ImportOptions {

  // What to do with domains already in the catalog.
  public const SKIP = 'skip';

  public const UPDATE = 'update';

  // Where the rows came from.
  public const SOURCE_LIST = 'list';

  public const SOURCE_CSV = 'csv';

  public const SOURCE_REGISTRAR = 'registrar';

  public function __construct(
    // The product type of new domains; it must have the domain trait.
    public readonly string $productType,
    // The store ID new domains are sold in.
    public readonly int $store,
    // The currency of prices that don't name one.
    public readonly string $currency,
    // The price of new domains whose row has none and no pricing rule
    // matches, or NULL for none.
    public readonly ?string $price = NULL,
    // The sale terms of new domains whose row doesn't give them.
    public readonly bool $buyNow = TRUE,
    public readonly bool $offers = TRUE,
    // Whether new domains are published.
    public readonly bool $published = TRUE,
    // Whether new domains are internal: kept for the owner's use, never for
    // sale, and so unpublished.
    public readonly bool $internal = FALSE,
    // SKIP or UPDATE domains already in the catalog. Updates take the row's
    // price and sale terms; whatever the row leaves out stays as it is.
    public readonly string $existing = self::SKIP,
    // Whether to look up, in public RDAP, the registrar of rows without one.
    public readonly bool $lookupRegistrars = FALSE,
    // Whether to only report what would happen, saving nothing.
    public readonly bool $dryRun = FALSE,
    // One of the SOURCE_* constants.
    public readonly string $source = self::SOURCE_CSV,
  ) {}

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

/**
 * One domain to import: a line of a list, a CSV row or a registrar record.
 */
final class ImportRow {

  public function __construct(
    // The line the row starts on, for the report; 0 for a domain that came
    // from a registrar account.
    public readonly int $line,
    // The row as given: CSV cells keyed by their header as written, or the
    // domain and price of a pasted line.
    public readonly array $raw,
    // The cells of the import's own columns (see ImportParser::COLUMNS),
    // keyed by column; empty cells are left out.
    public readonly array $values,
    // Why the row can't be imported, when that is known from the input alone.
    public readonly ?string $error = NULL,
  ) {}

  /**
   * Returns the value of one of the import's columns, or NULL when empty.
   *
   * Spreadsheets often fill unknown cells with "-" or "N/A": those are empty.
   */
  public function value(string $column): ?string {
    $value = trim((string) ($this->values[$column] ?? ''));
    return in_array(mb_strtolower($value), ['', '-', 'n/a'], TRUE) ? NULL : $value;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Reads pasted lists and CSV files into rows, and the values in their cells.
 */
final class ImportParser {

  /**
   * The import's columns, each with the headers it is known by.
   *
   * Headers match without regard to case, spaces or punctuation: "Domain
   * Name" is domain_name. When a file has several, the earlier alias wins.
   */
  public const COLUMNS = [
    'domain' => ['domain', 'domain_name', 'domainname', 'name'],
    'price' => ['price', 'selling_price', 'sale_price', 'asking_price', 'buy_now_price', 'bin_price', 'list_price'],
    'currency' => ['currency', 'currency_code'],
    'buy_now' => ['buy_now', 'buynow', 'bin'],
    'offers' => ['offers', 'accept_offers', 'make_offer', 'make_offers'],
    'minimum_offer' => ['minimum_offer', 'min_offer'],
    'description' => ['description', 'body'],
    'registrar' => ['registrar', 'registrar_name'],
    'expires' => ['expires', 'expiry', 'expiry_date', 'expiration', 'expiration_date', 'expires_on', 'expire_date'],
    'atom_premium' => ['atom_premium', 'premium'],
    'internal' => ['internal', 'not_for_sale'],
    'marketplaces' => ['marketplaces', 'list_on_marketplaces', 'marketplace'],
    'not_listed_on' => ['not_listed_on', 'not_on'],
    'floor_price' => ['floor_price', 'floor'],
    'lease_months' => ['lease_months', 'lease', 'lease_to_own'],
  ];

  /**
   * Reads a pasted list: one domain per line, optionally followed by a price.
   *
   * The price may name its currency ("example.com 1500 EUR"); a space, tab,
   * comma or semicolon separates it from the domain. Blank lines and lines
   * starting with # are left out.
   *
   * @return \Drupal\commerce_domains_import\ImportRow[]
   *   The rows, numbered by their line.
   */
  public static function parseList(string $text): array {
    $rows = [];
    foreach (preg_split('/\R/u', self::text($text)) ?: [] as $index => $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $parts = preg_split('/[\s,;]+/u', $line, 2) ?: [$line];
      $raw = ['domain' => $parts[0], 'price' => trim($parts[1] ?? '')];
      $rows[] = new ImportRow($index + 1, $raw, array_filter($raw, fn(string $value) => $value !== ''));
    }
    return $rows;
  }

  /**
   * Reads a CSV file with a header row.
   *
   * The delimiter (comma, semicolon or tab) is taken from the header row, or
   * from a "sep=" line before it, as spreadsheets write.
   *
   * @return \Drupal\commerce_domains_import\ImportRow[]
   *   The rows, numbered by the line they start on.
   *
   * @throws \InvalidArgumentException
   *   When the file has no header row, or no column holds the domain.
   */
  public static function parseCsv(string $content): array {
    $content = self::text($content);
    $line = 1;
    $delimiter = NULL;
    if (preg_match('/^sep=(.)\R/i', $content, $match)) {
      $delimiter = $match[1];
      $content = substr($content, strlen($match[0]));
      $line++;
    }
    $delimiter ??= self::delimiter($content);

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);
    $header = fgetcsv($handle, 0, $delimiter, '"', '');
    if (!is_array($header) || implode('', array_map('strval', $header)) === '') {
      fclose($handle);
      throw new \InvalidArgumentException((string) new TranslatableMarkup('The file is empty: it needs a header row naming the columns.'));
    }
    $header = array_map(fn($cell) => trim((string) $cell), $header);
    $columns = self::columns($header);
    if (!isset($columns['domain'])) {
      fclose($handle);
      throw new \InvalidArgumentException((string) new TranslatableMarkup('No domain column: name the column of domain names "domain" or "domain name".'));
    }

    $position = (int) ftell($handle);
    $line += substr_count($content, "\n", 0, $position);
    $rows = [];
    while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== FALSE) {
      // Quoted cells may span lines: count where each row starts.
      $start = $line;
      $next = (int) ftell($handle);
      $line += substr_count($content, "\n", $position, $next - $position);
      $position = $next;

      $cells = array_map(fn($cell) => trim((string) $cell), $cells);
      if (implode('', $cells) === '') {
        continue;
      }
      $raw = [];
      foreach ($header as $index => $name) {
        if ($name !== '' && !array_key_exists($name, $raw)) {
          $raw[$name] = $cells[$index] ?? '';
        }
      }
      $values = [];
      foreach ($columns as $column => $index) {
        if (($cells[$index] ?? '') !== '') {
          $values[$column] = $cells[$index];
        }
      }
      $rows[] = new ImportRow($start, $raw, $values);
    }
    fclose($handle);
    return $rows;
  }

  /**
   * Maps the import's columns to the position of the header holding them.
   *
   * @param string[] $header
   *   The header row.
   *
   * @return array<string, int>
   *   Positions keyed by column.
   */
  public static function columns(array $header): array {
    $keys = array_map(self::key(...), $header);
    $columns = [];
    foreach (self::COLUMNS as $column => $aliases) {
      foreach ($aliases as $alias) {
        $index = array_search($alias, $keys, TRUE);
        if ($index !== FALSE) {
          $columns[$column] = (int) $index;
          break;
        }
      }
    }
    return $columns;
  }

  /**
   * Normalizes a header for matching: "Domain Name" becomes domain_name.
   */
  public static function key(string $header): string {
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($header))), '_');
  }

  /**
   * Reads an amount such as 1500, 1,500.00, $1,500 or 1500 EUR.
   *
   * Use a dot for decimals; a comma is a decimal point only before one or two
   * final digits (1500,50).
   *
   * @return array{0: string, 1: string|null}
   *   The number, and the currency code when the value names one.
   *
   * @throws \InvalidArgumentException
   *   When the value is not an amount, or is below 0.
   */
  public static function amount(string $value): array {
    $number = trim($value);
    $currency = NULL;
    if (preg_match('/[A-Za-z]{3}/', $number, $match)) {
      $currency = strtoupper($match[0]);
      $number = (string) preg_replace('/' . $match[0] . '/', '', $number, 1);
    }
    $number = (string) preg_replace('/[\s\x{00A0}$€£¥]+/u', '', $number);
    if (str_starts_with($number, '-')) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Prices must be 0 or more, not @value.', ['@value' => $value]));
    }
    $number = match (TRUE) {
      (bool) preg_match('/^\d*\.?\d+$/', $number) => $number,
      (bool) preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $number) => str_replace(',', '', $number),
      (bool) preg_match('/^\d+,\d{1,2}$/', $number) => str_replace(',', '.', $number),
      default => throw new \InvalidArgumentException((string) new TranslatableMarkup('Not a price: @value.', ['@value' => $value])),
    };
    return [$number, $currency];
  }

  /**
   * Reads a number of months, 0 or more.
   *
   * @throws \InvalidArgumentException
   *   When the value is not a whole number.
   */
  public static function months(string $value): int {
    $value = trim($value);
    if (!preg_match('/^\d{1,3}$/', $value)) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Not a number of months: @value.', ['@value' => $value]));
    }
    return (int) $value;
  }

  /**
   * Reads a list, separated by commas, semicolons or vertical bars.
   *
   * @return string[]
   *   The items, trimmed, without empty ones.
   */
  public static function items(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $value) ?: []), fn($item) => $item !== ''));
  }

  /**
   * Reads a yes or no, such as yes, no, true, false, 1 or 0.
   *
   * @throws \InvalidArgumentException
   *   When the value is neither.
   */
  public static function flag(string $value): bool {
    return match (mb_strtolower(trim($value))) {
      '1', 'y', 'yes', 'true', 'on', 'x' => TRUE,
      '0', 'n', 'no', 'false', 'off' => FALSE,
      default => throw new \InvalidArgumentException((string) new TranslatableMarkup('Not yes or no: @value.', ['@value' => $value])),
    };
  }

  /**
   * Reads a date, such as 2027-03-01 or 03/01/2027, or a Unix time.
   *
   * Dates without a time zone are UTC, as registrars give them.
   *
   * @throws \InvalidArgumentException
   *   When the value is not a date.
   */
  public static function date(string $value): int {
    $value = trim($value);
    if (preg_match('/^\d{9,}$/', $value)) {
      return (int) $value;
    }
    // Shorter numbers would be read as a time of day, except 20270301.
    if (!preg_match('/^\d{1,7}$/', $value)) {
      try {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
      }
      catch (\Exception) {
        // Reported below.
      }
    }
    throw new \InvalidArgumentException((string) new TranslatableMarkup('Not a date: @value.', ['@value' => $value]));
  }

  /**
   * Returns text as UTF-8, without a byte order mark.
   *
   * Spreadsheets on Windows save CSV files as Windows-1252 or UTF-16 unless
   * told otherwise.
   */
  public static function text(string $text): string {
    if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
      $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16');
    }
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
      $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
      $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    return $text;
  }

  /**
   * Returns the delimiter the first line uses most: comma, semicolon or tab.
   */
  protected static function delimiter(string $content): string {
    $first = substr($content, 0, strcspn($content, "\r\n"));
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    // Sorting is stable, so a tie goes to the comma.
    arsort($counts);
    return reset($counts) > 0 ? (string) array_key_first($counts) : ',';
  }

}

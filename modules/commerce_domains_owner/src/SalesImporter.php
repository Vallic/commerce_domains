<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Imports domain sales from a CSV file: the price, commission and where.
 *
 * One row per domain, with a header row naming the columns, in any order and
 * case: domain (required), sold (the date), price (what the buyer paid,
 * optionally with its currency), currency, commission (percent), received
 * (what the seller got, which works out the commission), sold_via (a
 * provider's ID or name) and lease_months. A domain not yet sold needs the
 * date and the price, and goes off sale; for one already sold, the row's
 * values replace what it has and the empty cells leave the rest. A sale on
 * this site comes from its order, and is left as it is.
 */
class SalesImporter {

  // Accepted headers, by the column they fill.
  protected const COLUMNS = [
    'domain' => ['domain', 'domain_name', 'name'],
    'sold' => ['sold', 'sold_on_date', 'sale_date', 'date', 'domain_sold'],
    'price' => ['price', 'sold_for', 'sale_price', 'gross', 'domain_sale_price'],
    'currency' => ['currency'],
    'commission' => ['commission', 'commission_percent', 'fee', 'domain_sale_commission'],
    'received' => ['received', 'net', 'payout'],
    'via' => ['sold_via', 'via', 'sold_on', 'marketplace', 'channel', 'domain_sold_via'],
    'lease_months' => ['lease_months', 'lease', 'installments', 'domain_lease_months'],
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Availability $availability,
  ) {}

  /**
   * Reads a CSV file with a header row.
   *
   * The delimiter (comma, semicolon or tab) is taken from the header row, or
   * from a "sep=" line before it, as spreadsheets write.
   *
   * @param string $content
   *   The file.
   * @param array<string, string[]>|null $known
   *   Accepted headers by the column they fill, instead of COLUMNS.
   *
   * @return array<int, array<string, string>>
   *   The rows, keyed by the line they start on; each row's cells keyed by
   *   column (see COLUMNS), empty cells left out.
   *
   * @throws \InvalidArgumentException
   *   When there is no header row, or no column holds the domain.
   */
  public static function parse(string $content, ?array $known = NULL): array {
    // Spreadsheets save a byte order mark, and some old line endings.
    $content = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
    $line = 1;
    $delimiter = NULL;
    if (preg_match('/^sep=(.)\n/i', $content, $match)) {
      $delimiter = $match[1];
      $content = substr($content, strlen($match[0]));
      $line++;
    }
    if ($delimiter === NULL) {
      $first = strtok($content, "\n") ?: '';
      $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
      arsort($counts);
      $delimiter = (string) array_key_first($counts);
    }

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);
    $header = fgetcsv($handle, 0, $delimiter, '"', '');
    if (!is_array($header) || implode('', array_map('strval', $header)) === '') {
      fclose($handle);
      throw new \InvalidArgumentException((string) new TranslatableMarkup('The file is empty: it needs a header row naming the columns.'));
    }
    $columns = [];
    foreach ($header as $index => $name) {
      $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $name))) ?? '', '_');
      foreach ($known ?? self::COLUMNS as $column => $names) {
        if (in_array($key, $names, TRUE) && !in_array($column, $columns, TRUE)) {
          $columns[$index] = $column;
        }
      }
    }
    if (!in_array('domain', $columns, TRUE)) {
      fclose($handle);
      throw new \InvalidArgumentException((string) new TranslatableMarkup('No domain column: name the column of domain names "domain".'));
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
      $row = [];
      foreach ($columns as $index => $column) {
        $value = trim((string) ($cells[$index] ?? ''));
        if ($value !== '') {
          $row[$column] = $value;
        }
      }
      if ($row) {
        $rows[$start] = $row;
      }
    }
    fclose($handle);
    return $rows;
  }

  /**
   * Works out what each row changes.
   *
   * @param array<int, array<string, string>> $rows
   *   Rows from parse().
   *
   * @return array<int, array{domain: string, product: \Drupal\commerce_product\Entity\ProductInterface|null, changes: array<string, mixed>, new: bool, problem: string|null}>
   *   By line: the domain, its product, the new field values (empty when
   *   nothing changes), whether it is a new sale, and why the row can't be
   *   imported.
   */
  public function plan(array $rows): array {
    $names = [];
    foreach ($rows as $row) {
      $names[] = DomainName::normalize($row['domain'] ?? '');
    }
    $products = [];
    $names = array_values(array_filter(array_unique($names)));
    if ($names) {
      foreach ($this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => $names]) as $product) {
        if ($product instanceof ProductInterface) {
          $products[strtolower((string) $product->get('domain')->value)] = $product;
        }
      }
    }

    $plan = [];
    $seen = [];
    foreach ($rows as $line => $row) {
      $name = DomainName::normalize($row['domain'] ?? '');
      $product = $products[$name] ?? NULL;
      $entry = ['domain' => $name, 'product' => $product, 'changes' => [], 'new' => FALSE, 'problem' => NULL];
      try {
        if ($name === '') {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('No domain.'));
        }
        if (isset($seen[$name])) {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('Already on line @line.', ['@line' => $seen[$name]]));
        }
        $seen[$name] = $line;
        if (!$product) {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('Not in the catalog.'));
        }
        [$entry['changes'], $entry['new']] = $this->changes($product, $row);
      }
      catch (\InvalidArgumentException $e) {
        $entry['problem'] = $e->getMessage();
      }
      $plan[$line] = $entry;
    }
    return $plan;
  }

  /**
   * Records the sales a plan changes.
   *
   * @param array $plan
   *   The plan from plan().
   *
   * @return int
   *   How many domains changed.
   */
  public function apply(array $plan): int {
    $count = 0;
    foreach ($plan as $entry) {
      $product = $entry['product'];
      if ($entry['problem'] !== NULL || !$entry['changes'] || !$product instanceof ProductInterface) {
        continue;
      }
      foreach ($entry['changes'] as $field => $value) {
        $product->set($field, $value);
      }
      // A sale record, not an edit: the product keeps its changed time.
      $syncing = $product->isSyncing();
      $product->setSyncing(TRUE)->save();
      $product->setSyncing($syncing);
      if ($entry['new']) {
        $this->availability->markProductSold($product);
      }
      $count++;
    }
    return $count;
  }

  /**
   * Describes the changes of a plan entry, for people reading the preview.
   *
   * @param array<string, mixed> $changes
   *   New values keyed by field, from plan().
   *
   * @return string[]
   *   One line per field, e.g. "Commission: 15%".
   */
  public function describe(array $changes): array {
    $lines = [];
    foreach ($changes as $field => $value) {
      $lines[] = match ($field) {
        'domain_sold' => (string) new TranslatableMarkup('Sold: @date', ['@date' => date('Y-m-d', (int) $value)]),
        'domain_sale_price' => (string) new TranslatableMarkup('Sold for: @price', ['@price' => $value->getCurrencyCode() . ' ' . number_format((float) $value->getNumber(), 2)]),
        'domain_sale_commission' => (string) new TranslatableMarkup('Commission: @percent%', ['@percent' => rtrim(rtrim(number_format((float) $value, 2), '0'), '.')]),
        'domain_sold_via' => (string) new TranslatableMarkup('Sold on: @provider', ['@provider' => $this->entityTypeManager->getStorage('commerce_domains_provider')->load($value)?->label() ?? $value]),
        'domain_lease_months' => $value ? (string) new TranslatableMarkup('Lease to own: @count months', ['@count' => $value]) : (string) new TranslatableMarkup('Paid at once'),
        default => $field,
      };
    }
    return $lines;
  }

  /**
   * Returns what a row changes on a domain, and whether it is a new sale.
   *
   * @return array{0: array<string, mixed>, 1: bool}
   *   The new values keyed by field, and whether the domain wasn't sold.
   *
   * @throws \InvalidArgumentException
   *   When the row can't be imported.
   */
  protected function changes(ProductInterface $product, array $row): array {
    foreach (SaleRecorder::SALE_FIELDS as $field) {
      if (!$product->hasField($field)) {
        throw new \InvalidArgumentException((string) new TranslatableMarkup('The domain has no sale fields: turn on the Domain costs and sale trait for its product type.'));
      }
    }
    if (!$product->get('domain_sale_order')->isEmpty()) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Sold on this site in order @order: its sale comes from the order.', ['@order' => $product->get('domain_sale_order')->value]));
    }
    $new = $product->get('domain_sold')->isEmpty();
    $current = Costs::price($product, 'domain_sale_price');

    $sold = isset($row['sold']) ? self::date($row['sold']) : NULL;
    [$amount, $currency] = isset($row['price']) ? self::amount($row['price']) : [NULL, NULL];
    [$received, $received_currency] = isset($row['received']) ? self::amount($row['received']) : [NULL, NULL];
    $currency = strtoupper($row['currency'] ?? '') ?: $currency ?: $received_currency ?: $current?->getCurrencyCode() ?: $this->domainCurrency($product);
    if ($currency === NULL || !$this->entityTypeManager->getStorage('commerce_currency')->load($currency)) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Currency @currency is not on the site.', ['@currency' => $currency ?? '?']));
    }
    $commission = isset($row['commission']) ? self::percent($row['commission']) : NULL;
    // What the buyer paid, from what was received after the commission.
    if ($amount === NULL && $received !== NULL && $commission !== NULL && $commission < 100) {
      $amount = round($received / (1 - $commission / 100), 2);
    }
    $price_number = $amount ?? ($current && $current->getCurrencyCode() === $currency ? (float) $current->getNumber() : NULL);
    if ($received !== NULL && $commission === NULL && $price_number) {
      if ($received > $price_number) {
        $args = ['@received' => $received, '@price' => $price_number];
        throw new \InvalidArgumentException((string) new TranslatableMarkup('Received @received is more than the price @price.', $args));
      }
      $commission = round(100 * (1 - $received / $price_number), 2);
    }
    if ($new && ($sold === NULL || $price_number === NULL)) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Not sold yet: a sale needs the date it sold and the price.'));
    }

    $wanted = [];
    if ($sold !== NULL) {
      $wanted['domain_sold'] = $sold;
    }
    if ($amount !== NULL) {
      $wanted['domain_sale_price'] = new Price((string) $amount, $currency);
    }
    elseif ($current && isset($row['currency']) && $current->getCurrencyCode() !== $currency) {
      $wanted['domain_sale_price'] = new Price($current->getNumber(), $currency);
    }
    if ($commission !== NULL) {
      $wanted['domain_sale_commission'] = (string) $commission;
    }
    if (isset($row['via'])) {
      $wanted['domain_sold_via'] = $this->provider($row['via'])->id();
    }
    if (isset($row['lease_months'])) {
      if (!preg_match('/^\d+$/', $row['lease_months'])) {
        throw new \InvalidArgumentException((string) new TranslatableMarkup('Lease months @value is not a number.', ['@value' => $row['lease_months']]));
      }
      $months = (int) $row['lease_months'];
      $wanted['domain_lease_months'] = $months > 1 ? min($months, DomainCosts::MAX_LEASE_MONTHS) : NULL;
    }

    $changes = [];
    foreach ($wanted as $field => $value) {
      if (!self::same($product, $field, $value)) {
        $changes[$field] = $value;
      }
    }
    return [$changes, $new];
  }

  /**
   * Whether a field already holds a value.
   */
  protected static function same(ProductInterface $product, string $field, mixed $value): bool {
    $items = $product->get($field);
    return match ($field) {
      'domain_sale_price' => ($price = Costs::price($product, $field)) !== NULL && $value instanceof Price && $price->equals($value),
      'domain_sold_via' => $value === ($items->isEmpty() ? NULL : (string) $items->target_id),
      'domain_sale_commission' => !$items->isEmpty() && (float) $items->value === (float) $value,
      default => ($items->isEmpty() ? NULL : (int) $items->value) === $value,
    };
  }

  /**
   * Returns the provider a cell names, by ID or name.
   *
   * @throws \InvalidArgumentException
   */
  protected function provider(string $value): ProviderInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_provider');
    $provider = $storage->load($value) ?? $storage->load(strtolower($value));
    if (!$provider) {
      foreach ($storage->loadMultiple() as $candidate) {
        if (mb_strtolower((string) $candidate->label()) === mb_strtolower($value)) {
          $provider = $candidate;
          break;
        }
      }
    }
    if (!$provider instanceof ProviderInterface) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('No provider @value: give a provider ID or name.', ['@value' => $value]));
    }
    return $provider;
  }

  /**
   * Returns the currency the domain is priced in.
   */
  protected function domainCurrency(ProductInterface $product): ?string {
    foreach ($product->getVariations() as $variation) {
      if ($price = $variation->getPrice()) {
        return $price->getCurrencyCode();
      }
    }
    return NULL;
  }

  /**
   * Reads a date: 2026-10-30, 30.10.2026, 10/30/2026 or a Unix time.
   *
   * @throws \InvalidArgumentException
   */
  public static function date(string $value): int {
    if (preg_match('/^\d{9,10}$/', $value)) {
      return (int) $value;
    }
    $timezone = new \DateTimeZone(date_default_timezone_get());
    foreach (['!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s', '!d.m.Y', '!d.m.Y.', '!m/d/Y', '!Y/m/d'] as $format) {
      $date = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
      $errors = \DateTimeImmutable::getLastErrors();
      if ($date && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $date->getTimestamp();
      }
    }
    throw new \InvalidArgumentException((string) new TranslatableMarkup('@value is not a date: write it as 2026-10-30.', ['@value' => $value]));
  }

  /**
   * Reads an amount, with an optional currency: "1500", "$1,500.00 USD".
   *
   * @return array{0: float, 1: string|null}
   *   The number and the currency code, when the cell has one.
   *
   * @throws \InvalidArgumentException
   */
  public static function amount(string $value): array {
    $currency = preg_match('/\b([A-Za-z]{3})\b/', $value, $match) ? strtoupper($match[1]) : NULL;
    $number = preg_replace('/[^\d.,]/', '', $value) ?? '';
    // With both separators, the last one is the decimal point; a comma alone
    // is one too, unless three digits follow it.
    if (str_contains($number, ',') && str_contains($number, '.')) {
      $decimal = strrpos($number, ',') > strrpos($number, '.') ? ',' : '.';
      $number = str_replace($decimal === ',' ? '.' : ',', '', $number);
      $number = str_replace(',', '.', $number);
    }
    elseif (str_contains($number, ',')) {
      $number = preg_match('/,\d{3}$/', $number) ? str_replace(',', '', $number) : str_replace(',', '.', $number);
    }
    if ($number === '' || !is_numeric($number) || substr_count($number, '.') > 1) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('@value is not an amount.', ['@value' => $value]));
    }
    return [round((float) $number, 2), $currency];
  }

  /**
   * Reads a percentage: "15" or "15%".
   *
   * @throws \InvalidArgumentException
   */
  public static function percent(string $value): float {
    $number = str_replace(',', '.', rtrim(trim($value), '% '));
    if (!is_numeric($number) || (float) $number < 0 || (float) $number > 100) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Commission @value is not a percentage from 0 to 100.', ['@value' => $value]));
    }
    return round((float) $number, 2);
  }

}

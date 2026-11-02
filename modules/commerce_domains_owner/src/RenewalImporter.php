<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_owner\Entity\DomainRenewalInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Imports the renewals paid from a CSV file, or a registrar's order history.
 *
 * One row per charge, with a header row naming the columns, in any order and
 * case: domain (required), date, amount (optionally with its currency),
 * currency, years, type, registrar (a provider's ID or name) and reference
 * (the order number). Dynadot's order history reads as it is: its Item, Date
 * Submitted, Cost, Currency, Item Detail, Bill From, Order ID and Status.
 *
 * A file with a type column keeps only the rows it says are a renewal, a
 * transfer or a restore; anything else a registrar bills (registrations,
 * auctions, discounts, and in Dynadot's history a closeout's blank detail) is
 * passed over. Without that column, every row is a renewal. Rows already
 * imported add nothing. A charge replaces an estimated record for the same
 * domain from about the same time.
 */
class RenewalImporter {

  // Accepted headers, by the column they fill.
  protected const COLUMNS = [
    'domain' => ['domain', 'domain_name', 'name', 'item'],
    'date' => ['date', 'paid_on', 'renewed', 'renewal_date', 'date_submitted'],
    'amount' => ['amount', 'cost', 'price'],
    'currency' => ['currency'],
    'years' => ['years', 'period'],
    'type' => ['type', 'item_detail'],
    'registrar' => ['registrar', 'provider', 'bill_from'],
    'reference' => ['reference', 'order', 'order_id', 'order_number'],
    'status' => ['status'],
  ];

  // How far an estimated record may be from a charge it is replaced by.
  protected const ESTIMATE_WINDOW = 90 * 86400;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Reads a CSV file with a header row.
   *
   * @return array<int, array<string, string>>
   *   The rows, keyed by line; each row's cells keyed by column.
   *
   * @throws \InvalidArgumentException
   *   When there is no header row, or no column holds the domain.
   */
  public static function parse(string $content): array {
    return SalesImporter::parse($content, self::COLUMNS);
  }

  /**
   * Works out what each row adds.
   *
   * @param array<int, array<string, string>> $rows
   *   Rows from parse().
   *
   * @return array<int, array{domain: string, product: \Drupal\commerce_product\Entity\ProductInterface|null, values: array<string, mixed>|null, passed: bool, known: bool, problem: string|null}>
   *   By line: the domain, its product, the renewal's values, whether the row
   *   isn't a renewal at all, whether it was imported before, and why it can't
   *   be imported.
   */
  public function plan(array $rows): array {
    $typed = (bool) array_filter($rows, fn(array $row) => isset($row['type']));
    $names = [];
    foreach ($rows as $line => $row) {
      if (self::type($row, $typed) !== NULL && self::completed($row)) {
        $names[$line] = DomainName::normalize($row['domain'] ?? '');
      }
    }
    $products = $this->products($names);
    $known = $this->knownIds($products);

    $plan = [];
    $seen = [];
    foreach ($rows as $line => $row) {
      $name = $names[$line] ?? NULL;
      $entry = [
        'domain' => $name ?? (string) ($row['domain'] ?? ''),
        'product' => NULL,
        'values' => NULL,
        'passed' => $name === NULL,
        'known' => FALSE,
        'problem' => NULL,
      ];
      if ($name !== NULL) {
        $entry['product'] = $products[$name] ?? NULL;
        try {
          if ($name === '') {
            throw new \InvalidArgumentException((string) new TranslatableMarkup('No domain.'));
          }
          if (!$entry['product']) {
            throw new \InvalidArgumentException((string) new TranslatableMarkup('Not in the catalog.'));
          }
          $values = $this->values($entry['product'], $row, (string) self::type($row, $typed));
          $id = $values['external_id'];
          if (isset($seen[$name][$id])) {
            throw new \InvalidArgumentException((string) new TranslatableMarkup('Already on line @line.', ['@line' => $seen[$name][$id]]));
          }
          $seen[$name][$id] = $line;
          $entry['values'] = $values;
          $entry['known'] = isset($known[$entry['product']->id()][$id]);
        }
        catch (\InvalidArgumentException $e) {
          $entry['problem'] = $e->getMessage();
        }
      }
      $plan[$line] = $entry;
    }
    return $plan;
  }

  /**
   * Records the renewals of a plan.
   *
   * @param array $plan
   *   The plan from plan().
   *
   * @return int
   *   How many were added.
   */
  public function apply(array $plan): int {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_renewal');
    $count = 0;
    foreach ($plan as $entry) {
      if ($entry['problem'] !== NULL || $entry['known'] || !$entry['values']) {
        continue;
      }
      $this->replaceEstimates($entry['product'], (int) $entry['values']['paid']);
      $storage->create($entry['values'])->save();
      $count++;
    }
    return $count;
  }

  /**
   * Counts what a plan does.
   *
   * @param array $plan
   *   The plan from plan().
   *
   * @return array{new: int, known: int, passed: int, problems: int}
   *   How many rows add a renewal, were imported before, aren't renewals, and
   *   can't be imported.
   */
  public static function summary(array $plan): array {
    $summary = ['new' => 0, 'known' => 0, 'passed' => 0, 'problems' => 0];
    foreach ($plan as $entry) {
      $summary[match (TRUE) {
        $entry['passed'] => 'passed',
        $entry['problem'] !== NULL => 'problems',
        $entry['known'] => 'known',
        default => 'new',
      }]++;
    }
    return $summary;
  }

  /**
   * Describes a row's renewal, for people reading the preview.
   */
  public static function describe(array $values): string {
    /** @var \Drupal\commerce_price\Price $amount */
    $amount = $values['amount'];
    return (string) new TranslatableMarkup('@date: @type, @years year(s), @currency @amount', [
      '@date' => date('Y-m-d', (int) $values['paid']),
      '@type' => $values['type'],
      '@years' => $values['years'],
      '@currency' => $amount->getCurrencyCode(),
      '@amount' => number_format((float) $amount->getNumber(), 2),
    ]);
  }

  /**
   * Returns what a row records.
   *
   * @throws \InvalidArgumentException
   *   When the row can't be imported.
   */
  protected function values(ProductInterface $product, array $row, string $type): array {
    if (!isset($row['date'])) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('No date.'));
    }
    if (!isset($row['amount'])) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('No amount.'));
    }
    $paid = SalesImporter::date($row['date']);
    [$number, $currency] = SalesImporter::amount($row['amount']);
    // "US Dollar USD ($)" as well as "USD".
    if (isset($row['currency'])) {
      $currency = preg_match('/\b[A-Z]{3}\b/', $row['currency'], $match) ? $match[0] : strtoupper($row['currency']);
    }
    if ($currency === NULL || !$this->entityTypeManager->getStorage('commerce_currency')->load($currency)) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Currency @currency is not on the site.', ['@currency' => $currency ?? '?']));
    }
    $years = 1;
    if (isset($row['years']) && preg_match('/\d+/', $row['years'], $match)) {
      $years = (int) $match[0];
    }
    elseif (preg_match('/(\d+)\s*year/i', $row['type'] ?? '', $match)) {
      $years = (int) $match[1];
    }
    $years = max(1, $years);
    $provider = isset($row['registrar']) ? $this->provider($row['registrar']) : NULL;
    $reference = $row['reference'] ?? date('Y-m-d', $paid) . ':' . $number;
    return [
      'product' => $product->id(),
      'paid' => $paid,
      'years' => $years,
      'amount' => new Price((string) $number, $currency),
      'provider' => $provider?->id(),
      'type' => $type,
      'estimated' => FALSE,
      'source' => 'import',
      'external_id' => mb_substr($type . ':' . $reference, 0, 255),
    ];
  }

  /**
   * Returns the type of renewal a row is, or NULL when it isn't one.
   *
   * @param array<string, string> $row
   *   The row.
   * @param bool $typed
   *   Whether the file has a type column.
   */
  protected static function type(array $row, bool $typed): ?string {
    if (!$typed) {
      return DomainRenewalInterface::RENEWAL;
    }
    $text = strtolower($row['type'] ?? '');
    return match (TRUE) {
      str_contains($text, 'transfer away') => NULL,
      str_contains($text, 'renew') => DomainRenewalInterface::RENEWAL,
      str_contains($text, 'transfer') => DomainRenewalInterface::TRANSFER,
      str_contains($text, 'restore'), str_contains($text, 'redemption') => DomainRenewalInterface::RESTORE,
      default => NULL,
    };
  }

  /**
   * Whether a row's status, if it has one, says it went through.
   */
  protected static function completed(array $row): bool {
    return !isset($row['status']) || in_array(strtolower($row['status']), ['completed', 'complete', 'success', 'paid'], TRUE);
  }

  /**
   * Returns the catalog's domains among some names.
   *
   * @param string[] $names
   *   Domain names.
   *
   * @return array<string, \Drupal\commerce_product\Entity\ProductInterface>
   *   Products keyed by name.
   */
  protected function products(array $names): array {
    $products = [];
    $names = array_values(array_filter(array_unique($names)));
    foreach (array_chunk($names, 200) as $chunk) {
      foreach ($this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => $chunk]) as $product) {
        if ($product instanceof ProductInterface) {
          $products[strtolower((string) $product->get('domain')->value)] = $product;
        }
      }
    }
    return $products;
  }

  /**
   * Returns what was imported before, by product and external ID.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface[] $products
   *   The domains.
   *
   * @return array<int|string, array<string, bool>>
   *   TRUE by product ID and external ID.
   */
  protected function knownIds(array $products): array {
    $known = [];
    $ids = array_map(fn(ProductInterface $product) => $product->id(), array_values($products));
    $storage = $this->entityTypeManager->getStorage('commerce_domains_renewal');
    foreach (array_chunk($ids, 200) as $chunk) {
      /** @var \Drupal\commerce_domains_owner\Entity\DomainRenewalInterface $renewal */
      foreach ($storage->loadByProperties(['product' => $chunk]) as $renewal) {
        $known[$renewal->get('product')->target_id][$renewal->getExternalId()] = TRUE;
      }
    }
    return $known;
  }

  /**
   * Deletes the estimated records a charge from about the same time replaces.
   */
  protected function replaceEstimates(ProductInterface $product, int $paid): void {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_renewal');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('product', $product->id())
      ->condition('estimated', TRUE)
      ->condition('paid', [$paid - self::ESTIMATE_WINDOW, $paid + self::ESTIMATE_WINDOW], 'BETWEEN')
      ->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Returns the provider a cell names: its ID, or its name within the cell.
   *
   * "Dynadot  INC" is Dynadot.
   *
   * @throws \InvalidArgumentException
   */
  protected function provider(string $value): ProviderInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_provider');
    $provider = $storage->load(strtolower($value));
    if (!$provider) {
      foreach ($storage->loadMultiple() as $candidate) {
        if (str_contains(mb_strtolower($value), mb_strtolower((string) $candidate->label()))) {
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

}

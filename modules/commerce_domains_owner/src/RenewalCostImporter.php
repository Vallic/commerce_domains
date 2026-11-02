<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RenewalPricesInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\Entity\RenewalCost;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;

/**
 * Fills the table of renewal costs from a CSV file or a price list.
 *
 * A file has a header row naming its columns, in any order and case: extension
 * (required), renewal (the amount, optionally with its currency), currency,
 * and registrar (a provider's ID or name; without one the cost is the general
 * one).
 *
 * A registrar with the capability reports its own prices, those of the
 * account. Porkbun's public list needs no account, and fills the general costs
 * rather than a registrar's. Lists fill only the catalog's extensions.
 */
class RenewalCostImporter {

  // The source for Porkbun's public list, beside the providers' IDs.
  public const PORKBUN_LIST = 'porkbun_public';

  // Accepted headers, by the column they fill.
  protected const COLUMNS = [
    'extension' => ['extension', 'extensions', 'tld', 'tlds', 'domain_extension'],
    'amount' => ['renewal', 'renew', 'renewal_price', 'renewal_cost', 'amount', 'cost', 'price'],
    'currency' => ['currency', 'currency_code'],
    'registrar' => ['registrar', 'provider'],
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ClientInterface $httpClient,
    protected RenewalCosts $costs,
  ) {}

  /**
   * Returns the price lists to fill from.
   *
   * @return array<string, string>
   *   Labels keyed by source.
   */
  public function sources(): array {
    $sources = [];
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
      if ($provider instanceof ProviderInterface && $provider->status() && $provider->hasCapability(RenewalPricesInterface::class)) {
        $sources[$id] = (string) new TranslatableMarkup("@provider's prices for this account", ['@provider' => $provider->label()]);
      }
    }
    $sources[self::PORKBUN_LIST] = (string) new TranslatableMarkup("Porkbun's public prices, as the general costs");
    return $sources;
  }

  /**
   * Reads a CSV file with a header row.
   *
   * @return array<int, array<string, string>>
   *   The rows, keyed by line; each row's cells keyed by column.
   *
   * @throws \InvalidArgumentException
   *   When there is no header row, or no column holds the extension.
   */
  public static function parse(string $content): array {
    return SalesImporter::parse($content, self::COLUMNS, 'extension');
  }

  /**
   * Works out what each row of a file does. A file's costs replace those set.
   *
   * @param array<int, array<string, string>> $rows
   *   Rows from parse().
   *
   * @return array<int|string, array{tld: string, registrar: string|null, price: \Drupal\commerce_price\Price|null, current: \Drupal\commerce_price\Price|null, status: string, problem: string|null}>
   *   Entries by line. The status is new, replaced, same or kept.
   */
  public function planRows(array $rows): array {
    $plan = [];
    $seen = [];
    foreach ($rows as $line => $row) {
      $tld = RenewalCost::normalize($row['extension'] ?? '');
      try {
        if (!RenewalCost::isValid($tld)) {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('Write the extension like com or co.uk.'));
        }
        $registrar = isset($row['registrar']) ? $this->provider($row['registrar']) : NULL;
        [$number, $currency] = SalesImporter::amount($row['amount'] ?? '');
        $currency = strtoupper($row['currency'] ?? '') ?: $currency;
        if ($currency === NULL) {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('No currency.'));
        }
        if (!$this->entityTypeManager->getStorage('commerce_currency')->load($currency)) {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('The currency @currency is not on the site.', ['@currency' => $currency]));
        }
        $key = $tld . '|' . $registrar;
        if (isset($seen[$key])) {
          throw new \InvalidArgumentException((string) new TranslatableMarkup('Already on line @line.', ['@line' => $seen[$key]]));
        }
        $seen[$key] = $line;
        $plan[$line] = $this->entry($tld, $registrar, new Price((string) $number, $currency), TRUE);
      }
      catch (\InvalidArgumentException $e) {
        $plan[$line] = [
          'tld' => $tld,
          'registrar' => NULL,
          'price' => NULL,
          'current' => NULL,
          'status' => 'problem',
          'problem' => $e->getMessage(),
        ];
      }
    }
    return $plan;
  }

  /**
   * Works out what a price list does, for the catalog's extensions.
   *
   * A registrar's list says what it charges, so it replaces that registrar's
   * costs. Porkbun's list is only one registrar's prices, so it keeps the
   * costs already set unless told to replace them.
   *
   * @param string $source
   *   A source from sources().
   * @param bool $replace
   *   Whether Porkbun's list replaces the costs already set.
   *
   * @return array{plan: array<int|string, array>, unpriced: string[]}
   *   The entries, as planRows() returns them, and the catalog's extensions
   *   the list doesn't have.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the list can't be read.
   */
  public function planList(string $source, bool $replace = FALSE): array {
    $extensions = $this->costs->catalogExtensions();
    $registrar = NULL;
    if ($source === self::PORKBUN_LIST) {
      $amounts = $extensions ? (new PorkbunClient($this->httpClient, '', ''))->renewalPrices($extensions) : [];
      $prices = array_map(fn(string $number) => new Price($number, 'USD'), $amounts);
    }
    else {
      $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($source);
      $plugin = $provider instanceof ProviderInterface ? $provider->getPlugin() : NULL;
      if (!$plugin instanceof RenewalPricesInterface) {
        throw new ProviderException(sprintf('%s does not report renewal prices.', $source));
      }
      $prices = $extensions ? $plugin->renewalPrices($extensions) : [];
      $registrar = $source;
      $replace = TRUE;
    }
    $plan = [];
    foreach ($prices as $tld => $price) {
      if (in_array((string) $tld, $extensions, TRUE)) {
        $plan[$tld] = $this->entry((string) $tld, $registrar, $price, $replace);
      }
    }
    ksort($plan);
    return [
      'plan' => $plan,
      'unpriced' => array_values(array_diff($extensions, array_map('strval', array_keys($prices)))),
    ];
  }

  /**
   * Saves a plan's new and replaced costs.
   *
   * @return int
   *   How many were saved.
   */
  public function apply(array $plan): int {
    $count = 0;
    foreach ($plan as $entry) {
      if (in_array($entry['status'], ['new', 'replaced'], TRUE) && $entry['price']) {
        $this->costs->set($entry['tld'], $entry['registrar'], $entry['price']);
        $count++;
      }
    }
    return $count;
  }

  /**
   * Counts a plan's entries by status.
   *
   * @return array{new: int, replaced: int, same: int, kept: int, problem: int}
   *   The counts.
   */
  public static function summary(array $plan): array {
    $summary = ['new' => 0, 'replaced' => 0, 'same' => 0, 'kept' => 0, 'problem' => 0];
    foreach ($plan as $entry) {
      $summary[$entry['status']]++;
    }
    return $summary;
  }

  /**
   * Works out what one cost does.
   */
  protected function entry(string $tld, ?string $registrar, Price $price, bool $replace): array {
    $current = $this->costs->find($tld, $registrar)?->getCost();
    $status = match (TRUE) {
      !$current => 'new',
      $current->getCurrencyCode() === $price->getCurrencyCode() && $current->equals($price) => 'same',
      $replace => 'replaced',
      default => 'kept',
    };
    return [
      'tld' => $tld,
      'registrar' => $registrar,
      'price' => $price,
      'current' => $current,
      'status' => $status,
      'problem' => NULL,
    ];
  }

  /**
   * Finds a provider by ID or name, returning its ID.
   *
   * @throws \InvalidArgumentException
   */
  protected function provider(string $value): string {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_provider');
    if ($storage->load(strtolower($value))) {
      return strtolower($value);
    }
    foreach ($storage->loadMultiple() as $id => $provider) {
      if (mb_strtolower(trim($value)) === mb_strtolower((string) $provider->label())) {
        return (string) $id;
      }
    }
    throw new \InvalidArgumentException((string) new TranslatableMarkup('No provider @value: give a provider ID or name.', ['@value' => $value]));
  }

}

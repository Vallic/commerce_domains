<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;

/**
 * Fills the default renewal costs from a public price list.
 *
 * Porkbun publishes what a year's renewal costs for every extension it
 * sells, in US dollars, without an API key. Only the extensions of catalog
 * domains are filled in, and the costs already set stay as they are unless
 * asked to be replaced: they are yours, and Porkbun is one registrar's price.
 */
class RenewalCostDefaults {

  // The currency of the price list.
  public const CURRENCY = 'USD';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ClientInterface $httpClient,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Returns the extensions of the catalog's domains.
   *
   * @return string[]
   *   Extensions such as com and co.uk, sorted.
   */
  public function catalogExtensions(): array {
    if (!isset($this->entityFieldManager->getFieldMap()['commerce_product']['domain_extension'])) {
      return [];
    }
    // An aggregate query gives a row per group, not entity IDs.
    /** @var array<int, array<string, string|null>> $result */
    $result = $this->entityTypeManager->getStorage('commerce_product')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->exists('domain_extension')
      ->groupBy('domain_extension.value')
      ->execute();
    $extensions = [];
    foreach ($result as $row) {
      $extension = strtolower((string) ($row['domain_extension_value'] ?? ''));
      if ($extension !== '') {
        $extensions[] = $extension;
      }
    }
    sort($extensions);
    return array_values(array_unique($extensions));
  }

  /**
   * Works out the renewal costs Porkbun's price list fills in.
   *
   * @param bool $replace
   *   Whether to replace the costs already set for those extensions.
   *
   * @return array{costs: array<int, array{tld: string, number: string, currency_code: string}>, added: array<string, string>, replaced: array<string, string>, unpriced: string[]}
   *   The renewal costs to save, sorted by extension; the extensions added
   *   and replaced, with their price; and the catalog's extensions Porkbun
   *   doesn't sell.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the price list can't be read.
   */
  public function fromPorkbun(bool $replace = FALSE): array {
    $current = [];
    foreach ((array) $this->configFactory->get('commerce_domains_owner.settings')->get('renewal_costs') as $cost) {
      $current[(string) $cost['tld']] = $cost;
    }
    $extensions = $this->catalogExtensions();
    $prices = $extensions ? (new PorkbunClient($this->httpClient, '', ''))->renewalPrices($extensions) : [];
    $added = $replaced = [];
    foreach ($prices as $tld => $price) {
      if (!in_array($tld, $extensions, TRUE)) {
        continue;
      }
      if (isset($current[$tld])) {
        if (!$replace || ($current[$tld]['number'] === $price && $current[$tld]['currency_code'] === self::CURRENCY)) {
          continue;
        }
        $replaced[$tld] = $price;
      }
      else {
        $added[$tld] = $price;
      }
      $current[$tld] = ['tld' => $tld, 'number' => $price, 'currency_code' => self::CURRENCY];
    }
    ksort($current);
    return [
      'costs' => array_values($current),
      'added' => $added,
      'replaced' => $replaced,
      'unpriced' => array_values(array_diff($extensions, array_keys($prices))),
    ];
  }

  /**
   * Saves renewal costs, as fromPorkbun() returns them.
   */
  public function save(array $costs): void {
    $this->configFactory->getEditable('commerce_domains_owner.settings')->set('renewal_costs', $costs)->save();
  }

}

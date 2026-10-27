<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\FruitsClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;

/**
 * Lists domains on fruits.
 *
 * Lists the catalog's euro price times the price factor, as the net amount
 * the seller receives.
 */
#[CommerceDomainsProvider(
  id: 'fruits',
  label: new TranslatableMarkup('fruits'),
  description: new TranslatableMarkup('Pushes listings through the fruits API.'),
)]
final class Fruits extends MarketplacePluginBase implements CsvExportInterface {

  protected const CREDENTIALS = [
    'client_id' => 'FRUITS_CLIENT_ID',
    'client_secret' => 'FRUITS_CLIENT_SECRET',
  ];

  // Prices travel in cents.
  protected const MINOR_UNITS = 100;

  // Up to this many names are fetched one by one; more and the whole list is
  // cheaper.
  protected const SINGLE_FETCH_LIMIT = 20;

  // How long a push waits for fruits to process its import.
  protected const IMPORT_TIMEOUT = 60;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\FruitsClient|null
   */
  protected ?FruitsClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price_factor' => 1.05] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildListing(CatalogItem $item): ?array {
    $price = (int) round($item->price('EUR') * $this->priceFactor($item));
    // Listings have a price and nothing else.
    if ($price <= 0 || !$item->terms->buyNow) {
      return NULL;
    }
    return [
      'price' => $price * self::MINOR_UNITS,
      'currency' => 'eur',
      'is_net' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    $client = $this->client();
    if ($names !== NULL && count($names) <= self::SINGLE_FETCH_LIMIT) {
      $domains = array_filter(array_map($client->getDomain(...), array_values($names)));
    }
    else {
      $domains = $client->listDomains();
    }

    $listings = [];
    foreach ($domains as $domain) {
      // A domain without a product is in the portfolio but not for sale.
      $product = $domain['product'] ?? NULL;
      if (!isset($domain['name']) || !is_array($product)) {
        continue;
      }
      $listings[DomainName::normalize((string) $domain['name'])] = [
        'price' => isset($product['price']['amount']) ? (int) $product['price']['amount'] : NULL,
        'currency' => isset($product['price']['currency']) ? strtolower((string) $product['price']['currency']) : NULL,
        'is_net' => (bool) ($product['is_net'] ?? FALSE),
        'archived' => !empty($product['archived_at']),
        'state' => $domain['state'] ?? NULL,
      ];
    }
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    if ($field === 'price' && is_int($value)) {
      return $this->formatMoney($value / self::MINOR_UNITS, '€');
    }
    return parent::describeValue($field, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    // Imports can't unarchive, and someone archived it on purpose.
    return !empty($current['archived']) ? 'archived' : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * One import adds and reprices everything. fruits processes it in the
   * background; when it is still running after IMPORT_TIMEOUT, no errors are
   * reported and the next sync shows whether it landed.
   */
  public function saveListings(array $listings, array $current): array {
    $client = $this->client();
    $errors = [];
    foreach (array_chunk($listings, FruitsClient::IMPORT_LIMIT, TRUE) as $chunk) {
      $items = [];
      foreach ($chunk as $name => $listing) {
        $items[] = ['name' => $name] + $listing;
      }
      $import = $client->waitForImport($client->importDomains($items), self::IMPORT_TIMEOUT);
      if (($import['state'] ?? NULL) === 'failed') {
        $message = sprintf('fruits import %s failed.', $import['id'] ?? '');
        $errors += array_fill_keys(array_keys($chunk), $message);
      }
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $client = $this->client();
    return $client->eachDomain(array_values($names), $client->deleteDomain(...));
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Use an API client with the content.domain.read and content.domain.write scopes.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): FruitsClient {
    $this->assertReady();
    return $this->client ??= new FruitsClient($this->httpClient, (string) $this->configuration['client_id'], (string) $this->configuration['client_secret']);
  }

  /**
   * {@inheritdoc}
   */
  public function csvHeader(string $file): array {
    return ['Domain', 'Price', 'Currency', 'Pricing', 'Comment'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $listing = $this->buildListing($item);
    return $listing ? [$item->title, intdiv($listing['price'], self::MINOR_UNITS), 'EUR', 'net', ''] : NULL;
  }

}

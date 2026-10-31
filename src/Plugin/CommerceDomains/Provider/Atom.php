<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Clients\AtomClient;
use Drupal\commerce_domains\Clients\AtomRegistrarClient;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Registration;
use Drupal\commerce_domains\Appraisal;
use Drupal\commerce_domains\ListingStats;
use Drupal\commerce_domains\MarketplaceSale;
use Drupal\commerce_domains\ProviderException;

/**
 * Lists domains on Atom as standard listings.
 *
 * Atom premium names are curated and priced by Atom, and the API can't
 * remove them, so they are left alone: catalog domains flagged as Atom
 * premium are not managed here, and any premium listing on Atom is locked.
 * Standard listings are priced at the catalog price times the price factor.
 *
 * Registration details come from the separate Registrar API, which needs its
 * own token.
 */
#[CommerceDomainsProvider(
  id: 'atom',
  label: new TranslatableMarkup('Atom'),
  description: new TranslatableMarkup('Pushes standard listings through the Atom Seller API, and reads registrations through its Registrar API.'),
)]
final class Atom extends MarketplacePluginBase implements RegistrarInterface, CsvExportInterface, AppraisalInterface, ListingStatsInterface, SalesReportInterface, PremiumListingsInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_token' => 'ATOM_API_TOKEN',
    'user_id' => 'ATOM_USER_ID',
  ];

  protected const OPTIONAL_CREDENTIALS = [
    'registrar_token' => 'ATOM_REGISTRAR_TOKEN',
  ];

  // Listing states that mean a sale is under way or done: a buyer's hold, a
  // purchase, the transfer to the buyer, or sold.
  protected const SALE_STATUS_PATTERN = '/sold|hold|purchas|transfer/i';

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\AtomClient|null
   */
  protected ?AtomClient $client = NULL;

  /**
   * The Registrar API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\AtomRegistrarClient|null
   */
  protected ?AtomRegistrarClient $registrarClient = NULL;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    // Premium names are never listed here, so they need no factor of their own.
    return ['price_factor' => 1.05] + array_diff_key(parent::defaultConfiguration(), ['premium_price_factor' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function manages(CatalogItem $item): bool {
    return !$item->atomPremium;
  }

  /**
   * {@inheritdoc}
   */
  public function buildListing(CatalogItem $item): ?array {
    // Standard listings have a price and nothing else.
    $price = (int) round($item->price('USD') * $this->priceFactor($item));
    return $price > 0 && $item->terms->buyNow ? ['selling_price' => $price] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    // Domain details don't say whether a listing is premium, so always read
    // the lists, which are filtered by type.
    $listings = [];
    foreach (AtomClient::TYPES as $type) {
      foreach ($this->client()->listDomains($type) as $domain) {
        if (!isset($domain['domain_name'])) {
          continue;
        }
        $listings[DomainName::normalize((string) $domain['domain_name'])] ??= [
          'selling_price' => isset($domain['selling_price']) && is_numeric($domain['selling_price']) ? (int) $domain['selling_price'] : NULL,
          'status' => $domain['status'] ?? NULL,
          'type' => $type,
        ];
      }
    }
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    if ($field === 'selling_price' && is_int($value)) {
      return $this->formatMoney($value);
    }
    return parent::describeValue($field, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    if (($current['type'] ?? NULL) === 'premium') {
      return 'premium listing, managed by Atom';
    }
    return $this->saleStatus($current);
  }

  /**
   * {@inheritdoc}
   */
  public function isPremium(array $current): bool {
    return ($current['type'] ?? NULL) === 'premium';
  }

  /**
   * {@inheritdoc}
   *
   * Premium listings sell too; their type only keeps them locked.
   */
  public function saleStatus(array $current): ?string {
    $status = $current['status'] ?? NULL;
    return is_string($status) && preg_match(self::SALE_STATUS_PATTERN, $status) ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $client = $this->client();
    return $client->eachDomain(array_keys($listings), function (string $name) use ($client, $listings, $current): void {
      $price = $listings[$name]['selling_price'];
      if (isset($current[$name])) {
        $client->updateSellingPrice($name, $price);
      }
      else {
        $client->addDomain($name, $price);
      }
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $client = $this->client();
    return $client->eachDomain(array_values($names), $client->removeDomain(...));
  }

  /**
   * {@inheritdoc}
   */
  public function appraise(string $name): Appraisal {
    $record = $this->client()->appraise($name);
    $count = fn(string $key) => is_numeric($record[$key] ?? NULL) ? (int) $record[$key] : NULL;
    $signals = fn(string $key) => array_values(array_filter((array) ($record[$key] ?? []), 'is_string'));
    return new Appraisal(
      name: $name,
      value: $count('atom_appraisal'),
      score: is_numeric($record['domain_score'] ?? NULL) ? (float) $record['domain_score'] : NULL,
      tldCount: $count('tld_taken_count'),
      positive: $signals('positive_signals'),
      negative: $signals('negative_signals'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fetchStats(array $names): array {
    $stats = [];
    foreach ($names as $name) {
      try {
        $record = $this->client()->domainAnalytics($name);
      }
      catch (ProviderException $e) {
        if ($e->retryable) {
          throw $e;
        }
        continue;
      }
      $count = fn(string $key) => is_numeric($record[$key] ?? NULL) ? (int) $record[$key] : NULL;
      $stats[$name] = new ListingStats(
        views: $count('total_views'),
        offers: $count('total_offers'),
        saves: $count('total_shortlists'),
      );
    }
    return $stats;
  }

  /**
   * {@inheritdoc}
   *
   * Sale dates are taken as UTC; Atom doesn't say.
   */
  public function fetchSales(?array $names = NULL): array {
    $sales = [];
    foreach ($this->client()->allSales() as $record) {
      $name = $record['Domain Name'] ?? $record['domain_name'] ?? NULL;
      if (!is_string($name) || $name === '') {
        continue;
      }
      $name = DomainName::normalize($name);
      $amount = fn(string $key) => is_numeric($record[$key] ?? NULL) && (float) $record[$key] > 0 ? (float) $record[$key] : NULL;
      $terms = is_string($record['sale_terms'] ?? NULL) ? $record['sale_terms'] : '';
      $sale = new MarketplaceSale(
        name: $name,
        sold: self::timestamp($record['sale_date'] ?? NULL),
        price: $amount('sale_amount'),
        received: $amount('sale_amount_net'),
        leaseMonths: preg_match('/(\d+)\s*installment/i', $terms, $match) && (int) $match[1] > 1 ? (int) $match[1] : NULL,
        status: is_string($record['transfer_status'] ?? NULL) ? $record['transfer_status'] : NULL,
      );
      // The latest sale wins, for a domain sold more than once.
      if (!isset($sales[$name]) || (int) $sale->sold >= (int) $sales[$name]->sold) {
        $sales[$name] = $sale;
      }
    }
    return $names === NULL ? $sales : array_intersect_key($sales, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->registrarClient();
    return self::fewNames($names) ? array_filter(array_map($client->getDomain(...), array_values($names))) : $client->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['domain_name'])) {
      return NULL;
    }
    return new Registration(
      name: DomainName::normalize((string) $record['domain_name']),
      expires: self::timestamp($record['expiry_date'] ?? NULL),
      // The registrar lock, or the ICANN 60 days after registering.
      locked: isset($record['transfer_locked']) ? $record['transfer_locked'] || !empty($record['icann_transfer_lock']) : NULL,
      autoRenew: isset($record['auto_renew']) ? (bool) $record['auto_renew'] : NULL,
      status: isset($record['status']) ? (string) $record['status'] : NULL,
      registered: self::timestamp($record['created_date'] ?? NULL),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('The token and user ID are shown under API access in the Atom seller dashboard; the registrar token needs Registrar API access, requested there too.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): AtomClient {
    $this->assertReady();
    return $this->client ??= new AtomClient($this->httpClient, (string) $this->configuration['api_token'], (string) $this->configuration['user_id']);
  }

  /**
   * Returns the Registrar API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function registrarClient(): AtomRegistrarClient {
    $this->assertReady();
    $this->assertCredential('registrar_token');
    return $this->registrarClient ??= new AtomRegistrarClient($this->httpClient, (string) $this->configuration['registrar_token']);
  }

  /**
   * {@inheritdoc}
   */
  public function csvHeader(string $file): array {
    return ['Domain Name', 'Selling Price'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $listing = $this->manages($item) ? $this->buildListing($item) : NULL;
    return $listing ? [$item->title, $listing['selling_price']] : NULL;
  }

}

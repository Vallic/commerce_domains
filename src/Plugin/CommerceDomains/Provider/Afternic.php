<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Clients\AfternicClient;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists domains on Afternic through the Portfolio API.
 *
 * Lists with a custom lander: Buy Now at the catalog price times the price
 * factor, a floor of 80%, the base minimum offer, lease-to-own from $2,000,
 * and the make-offer option above $10,000. Atom premium names never show it
 * and take offers from the floor.
 */
#[CommerceDomainsProvider(
  id: 'afternic',
  label: new TranslatableMarkup('Afternic'),
  description: new TranslatableMarkup('Pushes listings through the Afternic Portfolio API.'),
)]
final class Afternic extends MarketplacePluginBase implements CsvExportInterface {

  protected const CREDENTIALS = [
    'api_key' => 'AFTERNIC_API_KEY',
    'api_secret' => 'AFTERNIC_API_SECRET',
  ];

  // Prices travel as whole dollars in microunits.
  protected const MICROS = 1_000_000;

  // Afternic rejects a minimum offer below $20.
  protected const MIN_OFFER_FLOOR = 20;

  // The longest lease Afternic offers, in monthly payments.
  protected const MAX_LEASE_MONTHS = 60;

  // Every setting the API lets us write. An upsert replaces the listing, so
  // the ones we don't manage (featured, displayName) are sent back unchanged.
  protected const WRITABLE_FIELDS = [
    'buynowPrice',
    'floorPrice',
    'minbidPrice',
    'maxLeasePeriod',
    'leasingEnabled',
    'displayName',
    'intakeStyle',
    'showLanderMakeOfferOption',
    'showLanderLeaseToOwnOption',
    'showLanderBuynowOption',
    'landingPage',
    'featured',
    'hidden',
  ];

  // Afternic says to review the sale before touching these. Both are sales:
  // ON_HOLD is a purchase under way (Afternic's "pending sale"), SOLD a done
  // one.
  protected const LOCKED_STATUSES = ['ON_HOLD', 'SOLD'];

  /**
   * The UUID generator, for request IDs.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuid;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\AfternicClient|null
   */
  protected ?AfternicClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->uuid = $container->get('uuid');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price_factor' => 1.1] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildListing(CatalogItem $item): ?array {
    $usd_price = $item->price('USD') * $this->priceFactor($item);
    $price = (int) round($usd_price);
    if ($price <= 0 || !$this->sells($item)) {
      return NULL;
    }
    $buy_now = $item->terms->buyNow;
    $offers = $item->terms->offers;

    // Without offers, nothing below the price is taken, by bid or broker.
    $min_offer = $offers ? $this->minimumOfferFor($item, $price, $item->atomPremium ? (int) ($usd_price * 0.8) : $this->minimumOffer($price)) : $price;
    $floor = $offers ? $this->floorPriceFor($item, $price, (int) ($usd_price * 0.8)) : $price;
    // Lease payments are worked out from the buy-now price.
    $lease_months = $buy_now ? $this->leaseMonthsFor($item, $this->leaseMonths($price), self::MAX_LEASE_MONTHS) : 0;

    $listing = [
      // Zero takes offers only.
      'buynowPrice' => ($buy_now ? $price : 0) * self::MICROS,
      'floorPrice' => $floor * self::MICROS,
      'minbidPrice' => max($min_offer, self::MIN_OFFER_FLOOR) * self::MICROS,
      'leasingEnabled' => $lease_months > 0,
      'landingPage' => 'SALES_TYPE',
      'intakeStyle' => 'CUSTOM_LANDER',
      'showLanderBuynowOption' => $buy_now,
      'showLanderLeaseToOwnOption' => $lease_months > 0,
      // Below $10,000, and for Atom premium names, the lander only offers the
      // buy-now price.
      'showLanderMakeOfferOption' => $offers && (!$buy_now || (!$item->atomPremium && $price > 10000)),
      'hidden' => FALSE,
    ];
    // The term only matters with leasing on; otherwise keep whatever is set.
    if ($lease_months) {
      $listing['maxLeasePeriod'] = $lease_months;
    }
    return $listing;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    $client = $this->client();
    if ($names === NULL) {
      $domains = $client->listDomains();
    }
    else {
      $domains = [];
      foreach (array_chunk(array_values($names), AfternicClient::BATCH_LIMIT) as $chunk) {
        array_push($domains, ...$client->getDomains($chunk));
      }
    }
    return array_column($domains, NULL, 'name');
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    if (str_ends_with($field, 'Price') && is_int($value)) {
      return $this->formatMoney(intdiv($value, self::MICROS));
    }
    return parent::describeValue($field, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    $status = $current['listStatus'] ?? NULL;
    return in_array($status, self::LOCKED_STATUSES, TRUE) ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saleStatus(array $current): ?string {
    // Every locked status is a sale.
    return $this->lockReason($current);
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $writable = array_flip(self::WRITABLE_FIELDS);
    $domains = [];
    foreach ($listings as $name => $listing) {
      $keep = array_intersect_key($current[$name] ?? [], $writable);
      $domains[] = ['name' => $name] + $listing + $keep;
    }

    $errors = [];
    foreach (array_chunk($domains, AfternicClient::BATCH_LIMIT) as $chunk) {
      $errors += $this->client()->upsertDomains($chunk);
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $errors = [];
    foreach (array_chunk(array_values($names), AfternicClient::BATCH_LIMIT) as $chunk) {
      $errors += $this->client()->deleteDomains($chunk);
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create them under Security → API Keys in the Afternic account profile.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): AfternicClient {
    $this->assertReady();
    return $this->client ??= new AfternicClient($this->httpClient, $this->uuid, (string) $this->configuration['api_key'], (string) $this->configuration['api_secret']);
  }

  /**
   * {@inheritdoc}
   *
   * The columns of Afternic's bulk upload template, with the API's values.
   */
  public function csvHeader(string $file): array {
    return [
      'Domain',
      'Buy Now Price',
      'Floor Price',
      'Min Offer',
      'Lease to Own',
      'Max Lease Period',
      'Sale Lander',
      'Show Buy Now Option',
      'Show Lease to Own Option',
      'Show Make Offer Option',
      'Hidden',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $listing = $this->buildListing($item);
    if ($listing === NULL) {
      return NULL;
    }
    $yes_no = fn(bool $value) => $value ? 'Y' : 'N';
    return [
      $item->title,
      intdiv($listing['buynowPrice'], self::MICROS),
      intdiv($listing['floorPrice'], self::MICROS),
      intdiv($listing['minbidPrice'], self::MICROS),
      $yes_no($listing['leasingEnabled']),
      $listing['maxLeasePeriod'] ?? '',
      'Custom Lander',
      $yes_no($listing['showLanderBuynowOption']),
      $yes_no($listing['showLanderLeaseToOwnOption']),
      $yes_no($listing['showLanderMakeOfferOption']),
      $yes_no($listing['hidden']),
    ];
  }

}

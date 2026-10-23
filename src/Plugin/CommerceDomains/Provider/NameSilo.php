<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Clients\NameSiloClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Lists NameSilo-registered domains in its marketplace; reads registrations.
 *
 * Listings are offer/counter-offer sales with a buy-now price, a hidden
 * reserve as the minimum offer, a payment plan of up to 12 months on dearer
 * names, and the longest end date NameSilo allows. Auctions, and sales that
 * are not active (e.g. awaiting payment), are left alone.
 */
#[CommerceDomainsProvider(
  id: 'namesilo',
  label: new TranslatableMarkup('NameSilo'),
  description: new TranslatableMarkup('Lists domains registered at NameSilo in its marketplace, and reads their registrations.'),
)]
final class NameSilo extends MarketplacePluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_key' => 'NAMESILO_API_KEY',
  ];

  // The sale overview shows these, so syncs compare them.
  protected const REPORTED_FIELDS = ['buy_now', 'reserve'];

  protected const MONEY_FIELDS = ['buy_now', 'reserve'];

  // The longest payment plan NameSilo offers.
  protected const MAX_PAYMENT_MONTHS = 12;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\NameSiloClient|null
   */
  protected ?NameSiloClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  public function manages(CatalogItem $item): bool {
    // Registered at a provider that uses this plugin.
    return $item->registrarPlugin === $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function buildListing(CatalogItem $item): ?array {
    $usd_price = $item->price('USD') * $this->priceFactor($item);
    // Whole dollars, with the cents dropped rather than rounded.
    $price = (int) $usd_price;
    if ($price <= 0) {
      return NULL;
    }
    $listing = [
      'buy_now' => $price,
      'reserve' => $this->minimumOffer($usd_price),
      'payment_plan_months' => $this->leaseMonths($usd_price, self::MAX_PAYMENT_MONTHS),
    ];
    if ($description = trim((string) $item->description)) {
      $listing['description'] = $description;
    }
    return $listing;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    // Amounts come as strings like "150.00", or "25.00 (hidden)".
    $amount = fn($value) => is_string($value) && preg_match('/^\s*(\d+(?:\.\d+)?)/', $value, $match) ? self::number($match[1]) : NULL;
    $listings = [];
    foreach ($this->client()->activeSales() as $sale) {
      if (!isset($sale['domain'])) {
        continue;
      }
      $listings[DomainName::normalize((string) $sale['domain'])] = [
        'buy_now' => $amount($sale['buy_now'] ?? NULL),
        'reserve' => $amount($sale['reserve'] ?? NULL),
        'status' => strtolower((string) ($sale['status'] ?? '')),
        'sale_type' => (string) ($sale['sale_type'] ?? ''),
      ];
    }
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function changedFields(array $wanted, array $current): array {
    return parent::changedFields(array_intersect_key($wanted, array_flip(self::REPORTED_FIELDS)), $current);
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    if (in_array($field, self::MONEY_FIELDS, TRUE) && (is_int($value) || is_float($value))) {
      return $this->formatMoney($value);
    }
    return parent::describeValue($field, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    if (stripos($current['sale_type'] ?? '', 'auction') !== FALSE) {
      return 'auction';
    }
    $status = $current['status'] ?? '';
    return $status !== '' && $status !== 'active' ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $client = $this->client();
    return $client->eachDomain(array_keys($listings), function (string $name) use ($client, $listings, $current): void {
      $listing = $listings[$name];
      $add = !isset($current[$name]);
      $months = $listing['payment_plan_months'];
      $parameters = [
        'buy_now' => $listing['buy_now'],
        'reserve' => $listing['reserve'],
        'show_reserve' => 0,
        'payment_plan_offered' => $months ? 1 : 0,
      ];
      if ($months) {
        $parameters['payment_plan_months'] = $months;
      }
      if ($add) {
        $parameters += ['sale_type' => 'offer_counter_offer', 'end_date_use_maximum' => 1];
      }
      if (isset($listing['description'])) {
        $parameters['description'] = $listing['description'];
      }
      $client->saveSale($name, $add, $parameters);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $client = $this->client();
    return $client->eachDomain(array_values($names), $client->cancelSale(...));
  }

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    // The account list has the expiry only; the lock and auto-renew flag need
    // a request per domain.
    return self::fewNames($names) ? array_filter(array_map($client->getDomainInfo(...), array_values($names))) : $client->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['domain'])) {
      return NULL;
    }
    $yes = fn(string $key) => isset($record[$key]) ? strtolower((string) $record[$key]) === 'yes' : NULL;
    return new Registration(
      name: DomainName::normalize((string) $record['domain']),
      expires: self::timestamp($record['expires'] ?? NULL),
      locked: $yes('locked'),
      autoRenew: $yes('auto_renew'),
      status: isset($record['status']) ? strtolower((string) $record['status']) : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create the key in the NameSilo API Manager; it is shown only once.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): NameSiloClient {
    $this->assertReady();
    return $this->client ??= new NameSiloClient($this->httpClient, (string) $this->configuration['api_key']);
  }

}

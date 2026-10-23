<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Registration;
use Drupal\commerce_domains\Clients\SpaceshipClient;

/**
 * Lists domains on Spaceship SellerHub, and reads registration details.
 *
 * Buy Now at the catalog price times the price factor, with offers from the
 * base minimum (the full price for Atom premium names). The API has no
 * lease-to-own or down-payment settings, so
 * those stay as set in SellerHub.
 */
#[CommerceDomainsProvider(
  id: 'spaceship',
  label: new TranslatableMarkup('Spaceship'),
  description: new TranslatableMarkup('Pushes listings to SellerHub through the Spaceship API.'),
)]
final class Spaceship extends MarketplacePluginBase implements RegistrarInterface {

  protected const CREDENTIALS = [
    'api_key' => 'SPACESHIP_API_KEY',
    'api_secret' => 'SPACESHIP_API_SECRET',
  ];

  // Up to this many names are fetched one by one; more and the whole list is
  // cheaper.
  protected const SINGLE_FETCH_LIMIT = 20;

  // Mid-sale, or stopped by hand in SellerHub.
  protected const LOCKED_STATUSES = ['saleProcessing', 'privateSale', 'leaseActive', 'sold', 'onSaleStopped'];

  protected const MONEY_FIELDS = ['binPrice', 'minPrice'];

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\SpaceshipClient|null
   */
  protected ?SpaceshipClient $client = NULL;

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
    $price = (int) round($item->price('USD') * $this->priceFactor($item));
    if ($price <= 0) {
      return NULL;
    }
    return [
      'binPriceEnabled' => TRUE,
      'binPrice' => $price,
      'minPriceEnabled' => TRUE,
      'minPrice' => $item->atomPremium ? $price : $this->minimumOffer($price),
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
      $listings[DomainName::normalize((string) $domain['name'])] = [
        'binPriceEnabled' => (bool) ($domain['binPriceEnabled'] ?? FALSE),
        'binPrice' => $this->amount($domain['binPrice'] ?? NULL),
        'minPriceEnabled' => (bool) ($domain['minPriceEnabled'] ?? FALSE),
        'minPrice' => $this->amount($domain['minPrice'] ?? NULL),
        'status' => $domain['status'] ?? NULL,
      ];
    }
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function fetchRegistrations(?array $names = NULL): array {
    $client = $this->client();
    if ($names !== NULL && count($names) <= self::SINGLE_FETCH_LIMIT) {
      $domains = array_filter(array_map($client->getRegistration(...), array_values($names)));
    }
    else {
      $domains = $client->listRegistrations();
    }

    $registrations = [];
    foreach ($domains as $domain) {
      $name = DomainName::normalize((string) $domain['name']);
      $expires = isset($domain['expirationDate']) ? strtotime((string) $domain['expirationDate']) : FALSE;
      $statuses = $domain['eppStatuses'] ?? NULL;
      $registrations[$name] = new Registration(
        name: $name,
        expires: $expires === FALSE ? NULL : $expires,
        locked: is_array($statuses) ? in_array('clientTransferProhibited', $statuses, TRUE) : NULL,
        autoRenew: isset($domain['autoRenew']) ? (bool) $domain['autoRenew'] : NULL,
        status: isset($domain['lifecycleStatus']) ? (string) $domain['lifecycleStatus'] : NULL,
      );
    }
    return $names === NULL ? $registrations : array_intersect_key($registrations, array_flip($names));
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
    $status = $current['status'] ?? NULL;
    return in_array($status, self::LOCKED_STATUSES, TRUE) ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $client = $this->client();
    return $client->eachDomain(array_keys($listings), function (string $name) use ($client, $listings, $current): void {
      $fields = $listings[$name];
      foreach (self::MONEY_FIELDS as $field) {
        if (isset($fields[$field])) {
          $fields[$field] = ['amount' => (string) $fields[$field], 'currency' => 'USD'];
        }
      }
      if (isset($current[$name])) {
        $client->updateDomain($name, $fields);
      }
      else {
        $client->createDomain(['name' => $this->unicode($name)] + $fields);
      }
    });
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
    return (string) $this->t('Create a key in the Spaceship API Manager with the SellerHub read and write scopes, plus domains read for registration details.');
  }

  /**
   * Reads an API money amount, which comes as a string like "1500.00".
   */
  protected function amount(?array $money): int|float|null {
    if (!isset($money['amount']) || !is_numeric($money['amount'])) {
      return NULL;
    }
    $amount = (float) $money['amount'];
    return floor($amount) == $amount ? (int) $amount : $amount;
  }

  /**
   * Converts an A-label name to the Unicode form SellerHub creates take.
   */
  protected function unicode(string $name): string {
    if (str_contains($name, 'xn--') && function_exists('idn_to_utf8')) {
      return idn_to_utf8($name, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46) ?: $name;
    }
    return $name;
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): SpaceshipClient {
    $this->assertReady();
    return $this->client ??= new SpaceshipClient($this->httpClient, (string) $this->configuration['api_key'], (string) $this->configuration['api_secret']);
  }

}

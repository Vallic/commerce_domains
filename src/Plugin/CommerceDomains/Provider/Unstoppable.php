<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Clients\UnstoppableClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Lists Unstoppable-registered domains for sale; reads registrations.
 *
 * Listings take offers above the minimum, and are created with a ten-year
 * expiry rather than the 90 days Unstoppable defaults to. Listings that are
 * mid-sale or awaiting approval are left alone.
 */
#[CommerceDomainsProvider(
  id: 'unstoppable',
  label: new TranslatableMarkup('Unstoppable Domains'),
  description: new TranslatableMarkup('Lists DNS domains registered at Unstoppable Domains for sale, and reads their registrations.'),
)]
final class Unstoppable extends MarketplacePluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_key' => 'UNSTOPPABLE_API_KEY',
  ];

  // Listing states a sync may change.
  protected const OPEN_STATUSES = ['open', 'open-on-request', 'open-not-signed', 'draft'];

  // The portfolio shows the price only, so syncs compare that.
  protected const REPORTED_FIELDS = ['price'];

  protected const MONEY_FIELDS = ['price', 'minimum_offer'];

  // How long new listings run; the API allows up to 100 years.
  protected const LISTING_DAYS = 3650;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\UnstoppableClient|null
   */
  protected ?UnstoppableClient $client = NULL;

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
    return ['price' => $price, 'minimum_offer' => $this->minimumOffer($usd_price)];
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    $listings = [];
    foreach ($this->client()->listDomains('for-sale') as $domain) {
      $listing = $domain['listing'] ?? NULL;
      if (!isset($domain['name'], $listing['id'])) {
        continue;
      }
      $listings[DomainName::normalize((string) $domain['name'])] = [
        'id' => $listing['id'],
        // In cents.
        'price' => is_numeric($listing['price'] ?? NULL) ? self::number($listing['price'] / 100) : NULL,
        'status' => (string) ($listing['status'] ?? ''),
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
    $status = $current['status'] ?? '';
    return $status !== '' && !in_array($status, self::OPEN_STATUSES, TRUE) ? $status : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $create = $update = [];
    foreach ($listings as $name => $listing) {
      $item = [
        'priceInCents' => $listing['price'] * 100,
        'listingSettings' => [
          'isOfferFeatureEnabled' => TRUE,
          'minOfferAmountInCents' => $listing['minimum_offer'] * 100,
        ],
      ];
      if (isset($current[$name]['id'])) {
        $update[] = ['id' => $current[$name]['id']] + $item;
      }
      else {
        $expires = (new \DateTimeImmutable('+' . self::LISTING_DAYS . ' days'))->format('Y-m-d');
        $create[] = ['domainName' => $name] + $item + ['expiresAt' => $expires];
      }
    }
    $client = $this->client();
    $names = array_flip(array_map(fn(array $listing) => $listing['id'], array_intersect_key($current, $listings)));
    return $this->errors($client->batch('ud_listing_create', 'domains', $create), $names)
      + $this->errors($client->batch('ud_listing_update', 'listings', $update), $names);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $listings = $this->fetchListings($names);
    if (!$listings) {
      return [];
    }
    $ids = array_column($listings, 'id');
    $results = $this->client()->batch('ud_listing_cancel', 'listingIds', $ids);
    return $this->errors($results, array_combine($ids, array_keys($listings)));
  }

  /**
   * Collects the failures of a batch action.
   *
   * @param array[] $results
   *   The per-item results.
   * @param array $names
   *   Domain names keyed by listing ID, for results without a name.
   *
   * @return array<string, string>
   *   Error messages keyed by domain name.
   */
  protected function errors(array $results, array $names): array {
    $errors = [];
    foreach ($results as $result) {
      if (!empty($result['success'])) {
        continue;
      }
      $name = $result['domainName'] ?? $names[$result['listingId'] ?? ''] ?? NULL;
      if (is_string($name)) {
        $errors[DomainName::normalize($name)] = is_string($result['error'] ?? NULL) ? $result['error'] : 'Not saved.';
      }
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    // Single domains come with the transfer flag; the portfolio list doesn't.
    return self::fewNames($names) ? $client->getDomains($names) : $client->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    // Portfolio records have the dates at the top, single domains under
    // lifecycle.
    $name = $record['name'] ?? $record['domain'] ?? NULL;
    if (!is_string($name)) {
      return NULL;
    }
    $lifecycle = $record['lifecycle'] ?? $record;
    // No auto-renewal subscription at all means it won't renew.
    $auto_renew = array_key_exists('autoRenewal', $lifecycle) ? ($lifecycle['autoRenewal']['status'] ?? NULL) === 'active' : NULL;
    $transfer_out = $record['flags']['DNS_TRANSFER_OUT']['status'] ?? NULL;
    return new Registration(
      name: DomainName::normalize($name),
      expires: self::timestamp($lifecycle['expiresAt'] ?? NULL),
      locked: is_string($transfer_out) ? $transfer_out === 'DISABLED' : NULL,
      autoRenew: $auto_renew,
      status: ($lifecycle['transferStatus'] ?? NULL) === 'pending' ? 'pending transfer' : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create an MCP API key under Account settings → Advanced at unstoppabledomains.com.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): UnstoppableClient {
    $this->assertReady();
    return $this->client ??= new UnstoppableClient($this->httpClient, (string) $this->configuration['api_key']);
  }

}

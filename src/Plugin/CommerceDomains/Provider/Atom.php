<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Clients\AtomClient;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;

/**
 * Lists domains on Atom as standard listings.
 *
 * Atom premium names are curated and priced by Atom, and the API can't
 * remove them, so they are left alone: catalog domains flagged as Atom
 * premium are not managed here, and any premium listing on Atom is locked.
 * Standard listings are priced at the catalog price times the price factor.
 */
#[CommerceDomainsProvider(
  id: 'atom',
  label: new TranslatableMarkup('Atom'),
  description: new TranslatableMarkup('Pushes standard listings through the Atom Seller API.'),
)]
final class Atom extends MarketplacePluginBase {

  protected const CREDENTIALS = [
    'api_token' => 'ATOM_API_TOKEN',
    'user_id' => 'ATOM_USER_ID',
  ];

  // Listing states that mean a sale is under way or done.
  protected const LOCKED_STATUS_PATTERN = '/sold|hold|purchas|transfer/i';

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\AtomClient|null
   */
  protected ?AtomClient $client = NULL;

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
    $price = (int) round($item->price('USD') * $this->priceFactor($item));
    return $price > 0 ? ['selling_price' => $price] : NULL;
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
    $status = $current['status'] ?? NULL;
    return is_string($status) && preg_match(self::LOCKED_STATUS_PATTERN, $status) ? $status : NULL;
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
  protected function credentialsHelp(): string {
    return (string) $this->t('Both are shown under API access in the Atom seller dashboard.');
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

}

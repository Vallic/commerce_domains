<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\DynadotClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;

/**
 * Lists domains registered at Dynadot in the Dynadot marketplace.
 *
 * The API only lists domains in the account itself, so this provider manages
 * the domains whose registrar is a Dynadot provider; names registered
 * elsewhere need Dynadot's external listing upload.
 *
 * Dynadot only reports a listing's price back, so the other settings are
 * sent along whenever the price changes but can't be checked on their own.
 */
#[CommerceDomainsProvider(
  id: 'dynadot',
  label: new TranslatableMarkup('Dynadot'),
  description: new TranslatableMarkup('Lists domains registered at Dynadot in its marketplace through the Dynadot API.'),
)]
final class Dynadot extends MarketplacePluginBase {

  protected const CREDENTIALS = [
    'api_key' => 'DYNADOT_API_KEY',
  ];

  // Dynadot allows installment plans of 2 to 12 months.
  protected const MAX_INSTALLMENTS = 12;

  // The fields get_listing_item reports, so the only ones a sync can compare.
  protected const REPORTED_FIELDS = ['price'];

  protected const MONEY_FIELDS = ['price', 'minimum_offer'];

  // Requests per minute at each API price level.
  protected const RATE_LIMITS = [
    60 => 'Regular (1 request a second)',
    600 => 'Bulk (10 a second)',
    6000 => 'Super Bulk (100 a second)',
  ];

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\DynadotClient|null
   */
  protected ?DynadotClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price_factor' => 1.05, 'requests_per_minute' => 60] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['requests_per_minute'] = [
      '#type' => 'select',
      '#title' => $this->t('API price level'),
      '#description' => $this->t('Dynadot limits API requests by account level. A full sync reads every listing one at a time, so at Regular it takes about ten minutes.'),
      '#options' => self::RATE_LIMITS,
      '#default_value' => $this->configuration['requests_per_minute'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['requests_per_minute'] = (int) $form_state->getValue('requests_per_minute');
  }

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
    // Atom premium names get the same minimum offer as the rest here.
    $min_offer = (int) ($price < 10000 ? $usd_price * 0.5 : $usd_price * 0.25);
    $installments = $this->leaseMonths($usd_price, self::MAX_INSTALLMENTS);

    $listing = [
      'price' => $price,
      'minimum_offer' => $min_offer,
      'installment' => $installments > 0,
      'category' => 'business',
    ];
    if ($installments) {
      $listing['maximum_installments'] = $installments;
    }
    if ($description = trim((string) $item->description)) {
      $listing['description'] = $description;
    }
    return $listing;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    $client = $this->client();

    // The account list says which domains are for sale; prices need a
    // request per listing.
    $for_sale = [];
    foreach ($client->listDomains() as $domain) {
      if (isset($domain['Name']) && strtolower((string) ($domain['isForSale'] ?? '')) === 'yes') {
        $for_sale[] = DomainName::normalize((string) $domain['Name']);
      }
    }
    if ($names !== NULL) {
      $for_sale = array_values(array_intersect($for_sale, $names));
    }

    $listings = [];
    foreach ($for_sale as $name) {
      $listing = $client->getListingItem($name);
      $price = $listing['Price'] ?? NULL;
      $listings[$name] = [
        // Unknown when the listing can't be read, so the next push resets it.
        'price' => is_numeric($price) ? $this->number($price) : NULL,
        'type' => $listing['Type'] ?? NULL,
        'pending_sale' => strtolower((string) ($listing['PendingSaleLocked'] ?? '')) === 'yes',
      ];
    }
    return $listings;
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
    if ($field === 'description' && is_string($value)) {
      return mb_strlen($value) . ' characters';
    }
    return parent::describeValue($field, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    return !empty($current['pending_sale']) ? 'pending sale' : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $client = $this->client();
    return $client->eachDomain(array_keys($listings), function (string $name) use ($client, $listings): void {
      $listing = $listings[$name];
      $client->setForSale($name, [
        'listing_type' => 'buy_now_and_make_offer',
        'price' => $listing['price'],
        'minimum_offer' => $listing['minimum_offer'],
        'installment' => $listing['installment'] ? 'Yes' : 'No',
      ] + array_intersect_key($listing, array_flip(['maximum_installments', 'category', 'description'])));
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
  protected function credentialsHelp(): string {
    return (string) $this->t("Generate it under Tools → API in the Dynadot account, and allow this server's IP there. Prices are sent in the account's currency, which must be US dollars.");
  }

  /**
   * Reads a numeric string as an int when it is whole.
   */
  protected function number(string|int|float $value): int|float {
    $value = (float) $value;
    return floor($value) == $value ? (int) $value : $value;
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): DynadotClient {
    $this->assertReady();
    return $this->client ??= new DynadotClient($this->httpClient, (string) $this->configuration['api_key'], (int) $this->configuration['requests_per_minute']);
  }

}

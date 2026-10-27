<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\DynadotClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;

/**
 * Lists Dynadot-registered domains in its marketplace; manages registrations.
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
final class Dynadot extends MarketplacePluginBase implements DomainManagementInterface, CsvExportInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_key' => 'DYNADOT_API_KEY',
  ];

  // Regular accounts; higher levels allow more.
  protected const REQUESTS_PER_MINUTE = 60;

  // Dynadot allows installment plans of 2 to 12 months.
  protected const MAX_INSTALLMENTS = 12;

  // The fields get_listing_item reports, so the only ones a sync can compare.
  protected const REPORTED_FIELDS = ['price', 'listing_type'];

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
    return ['price_factor' => 1.05] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    // The limit depends on the account level, so offer the levels.
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
    if ($price <= 0 || !$this->sells($item)) {
      return NULL;
    }
    $buy_now = $item->terms->buyNow;
    $offers = $item->terms->offers;
    // Atom premium names get the same minimum offer as the rest here.
    $min_offer = $offers ? $this->minimumOfferFor($item, $price, (int) ($price < 10000 ? $usd_price * 0.5 : $usd_price * 0.25)) : $price;
    $installments = $buy_now ? $this->leaseMonthsFor($item, $this->leaseMonths($usd_price, self::MAX_INSTALLMENTS), self::MAX_INSTALLMENTS) : 0;

    $listing = [
      'listing_type' => match (TRUE) {
        !$offers => 'buy_now',
        !$buy_now => 'make_offer',
        default => 'buy_now_and_make_offer',
      },
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
        'price' => is_numeric($price) ? self::number($price) : NULL,
        'type' => $listing['Type'] ?? NULL,
        'listing_type' => self::listingType($listing['Type'] ?? NULL),
        'pending_sale' => strtolower((string) ($listing['PendingSaleLocked'] ?? '')) === 'yes',
      ];
    }
    return $listings;
  }

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    return self::fewNames($names) ? array_filter(array_map($client->getDomainInfo(...), array_values($names))) : $client->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  public function getNameservers(string $name): array {
    return array_map('strtolower', $this->client()->getNameservers($name));
  }

  /**
   * {@inheritdoc}
   */
  public function setNameservers(string $name, array $nameservers): void {
    $this->client()->setNameservers($name, $nameservers);
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    if (!$locked) {
      throw new ProviderException('The Dynadot API cannot unlock domains; unlock it in the Dynadot account.');
    }
    $this->client()->lockDomain($name);
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    $this->client()->setRenewOption($name, $auto_renew ? 'auto' : 'donot');
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    return $this->client()->getTransferAuthCode($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['Name'])) {
      return NULL;
    }
    $expiration = $record['Expiration'] ?? NULL;
    $renew = strtolower((string) ($record['RenewOption'] ?? ''));
    return new Registration(
      name: DomainName::normalize((string) $record['Name']),
      // Milliseconds since the epoch.
      expires: is_numeric($expiration) ? intdiv((int) $expiration, 1000) : NULL,
      locked: isset($record['Locked']) ? strtolower((string) $record['Locked']) === 'yes' : NULL,
      // "auto-renew", "do not renew", or "no renew option" when unset.
      autoRenew: $renew === '' ? NULL : str_contains($renew, 'auto'),
      status: isset($record['Status']) ? (string) $record['Status'] : NULL,
    );
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
        'listing_type' => $listing['listing_type'] ?? 'buy_now_and_make_offer',
        'price' => $listing['price'],
        'minimum_offer' => $listing['minimum_offer'],
        'installment' => $listing['installment'] ? 'Yes' : 'No',
      ] + array_intersect_key($listing, array_flip(['maximum_installments', 'category', 'description'])));
    });
  }

  /**
   * Maps the type Dynadot reports to the listing type it takes.
   *
   * Single listings say "Buy Now + Make Offer"; lists say BIN or MAKE_OFFER.
   */
  protected static function listingType(mixed $type): ?string {
    if (!is_string($type) || $type === '') {
      return NULL;
    }
    $buy_now = (bool) preg_match('/buy\s*now|\bbin\b/i', $type);
    $offers = (bool) preg_match('/offer/i', $type);
    return match (TRUE) {
      $buy_now && $offers => 'buy_now_and_make_offer',
      $offers => 'make_offer',
      $buy_now => 'buy_now',
      default => NULL,
    };
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
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): DynadotClient {
    $this->assertReady();
    return $this->client ??= new DynadotClient($this->httpClient, (string) $this->configuration['api_key'], $this->requestsPerMinute());
  }

  /**
   * {@inheritdoc}
   *
   * Dynadot takes two files: one for domains in the account, and one for
   * domains registered elsewhere, which the API can't list.
   */
  public function csvFiles(): array {
    return [
      'listings' => (string) $this->t('Domains at Dynadot'),
      'external' => (string) $this->t('Domains at other registrars'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function csvHeader(string $file): array {
    if ($file === 'external') {
      return [
        'Domain',
        'Currency',
        'Buy Now Price',
        'Enable Offers',
        'Minimum Offer',
        'Description',
        'Enable For-Sale Landing Page',
      ];
    }
    return [
      'Domain',
      'Currency',
      'Buy Now Price',
      'Enable Offers',
      'Minimum Offer',
      'Category',
      'Sub-Category',
      'Includes Website (Yes/No)',
      "Description(can't use comma)",
      'Enable Installments',
      'Maximum Installments (2-12months)',
      'Enable For-Sale Landing Page',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $external = $file === 'external';
    $listing = $this->manages($item) !== $external ? $this->buildListing($item) : NULL;
    if ($listing === NULL) {
      return NULL;
    }
    $yes_no = fn(bool $value) => $value ? 'Yes' : 'No';
    $buy_now = $listing['listing_type'] === 'make_offer' ? '' : '$' . $listing['price'];
    $offers = $listing['listing_type'] !== 'buy_now';
    // Descriptions go without full stops.
    $description = self::csvText($listing['description'] ?? NULL, ['.']);
    if ($external) {
      return [$item->title, 'USD', $buy_now, $yes_no($offers), '$' . $listing['minimum_offer'], $description, 'No'];
    }
    return [
      $item->title,
      'USD',
      $buy_now,
      $yes_no($offers),
      '$' . $listing['minimum_offer'],
      ucfirst($listing['category']),
      '',
      'No',
      $description,
      $yes_no($listing['installment']),
      $listing['maximum_installments'] ?? 0,
      'No',
    ];
  }

}

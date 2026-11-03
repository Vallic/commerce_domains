<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_domains\Registration;
use Drupal\commerce_price\Price;
use Drupal\commerce_domains\Clients\SpaceshipClient;

/**
 * Lists domains on Spaceship SellerHub, and reads and manages registrations.
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
final class Spaceship extends MarketplacePluginBase implements DomainManagementInterface, DnsRecordsInterface, CsvExportInterface, PaymentLinksInterface {

  use DnsRecordByRecordTrait;
  use RegistrarTrait;

  // Where each record type keeps its value, for the types edited here. An
  // MX also has its preference, and a CAA its flag and tag.
  protected const DNS_VALUE_FIELDS = [
    'A' => 'address',
    'AAAA' => 'address',
    'CNAME' => 'cname',
    'ALIAS' => 'aliasName',
    'MX' => 'exchange',
    'TXT' => 'value',
    'NS' => 'nameserver',
    'CAA' => 'value',
  ];

  protected const CREDENTIALS = [
    'api_key' => 'SPACESHIP_API_KEY',
    'api_secret' => 'SPACESHIP_API_SECRET',
  ];

  // Up to this many names are fetched one by one; more and the whole list is
  // cheaper.
  protected const SINGLE_FETCH_LIMIT = 20;

  // Mid-sale, or stopped by hand in SellerHub.
  // The longest lease its file takes, in monthly payments.
  protected const MAX_LEASE_MONTHS = 36;

  protected const LOCKED_STATUSES = ['saleProcessing', 'privateSale', 'leaseActive', 'sold', 'onSaleStopped'];

  // The locked statuses that are sales: a purchase under way, an open
  // SafePay transaction, a lease-to-own not yet paid off, or sold.
  protected const SALE_STATUSES = ['saleProcessing', 'privateSale', 'leaseActive', 'sold'];

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
    if ($price <= 0 || !$this->sells($item)) {
      return NULL;
    }
    return [
      'binPriceEnabled' => $item->terms->buyNow,
      'binPrice' => $price,
      'minPriceEnabled' => $item->terms->offers,
      'minPrice' => $this->minimumOfferFor($item, $price, $item->atomPremium ? $price : $this->minimumOffer($price)),
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
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    return self::fewNames($names) ? array_filter(array_map($client->getRegistration(...), array_values($names))) : $client->listRegistrations();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['name'])) {
      return NULL;
    }
    $statuses = $record['eppStatuses'] ?? NULL;
    return new Registration(
      name: DomainName::normalize((string) $record['name']),
      expires: self::timestamp($record['expirationDate'] ?? NULL),
      locked: is_array($statuses) ? in_array('clientTransferProhibited', $statuses, TRUE) : NULL,
      autoRenew: isset($record['autoRenew']) ? (bool) $record['autoRenew'] : NULL,
      status: isset($record['lifecycleStatus']) ? (string) $record['lifecycleStatus'] : NULL,
      registered: self::timestamp($record['registrationDate'] ?? NULL),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Only the records the owner made (group "custom"): those Spaceship adds
   * for its products, or for personal nameservers, are its own. Types not
   * edited here, such as SRV, are kept as read, to be deleted as they are.
   */
  public function getDnsRecords(string $name): ?array {
    if (($this->client()->getRegistration($name)['nameservers']['provider'] ?? NULL) !== 'basic') {
      return NULL;
    }
    $records = [];
    foreach ($this->client()->listDnsRecords($name) as $item) {
      if (($item['group']['type'] ?? 'custom') !== 'custom') {
        continue;
      }
      $type = strtoupper((string) ($item['type'] ?? ''));
      $field = self::DNS_VALUE_FIELDS[$type] ?? NULL;
      $value = $field ? (string) ($item[$field] ?? '') : '';
      if ($type === 'CAA') {
        $value = sprintf('%d %s "%s"', $item['flag'] ?? 0, $item['tag'] ?? 'issue', $value);
      }
      $raw = array_diff_key($item, ['ttl' => TRUE, 'group' => TRUE]);
      $records[] = new DnsRecord(
        type: $type,
        host: DnsRecord::relativeHost((string) ($item['name'] ?? '@'), $name),
        value: $field ? $value : json_encode($raw),
        priority: $type === 'MX' && isset($item['preference']) ? (int) $item['preference'] : NULL,
        ttl: isset($item['ttl']) ? (int) $item['ttl'] : NULL,
        extra: $field ? [] : ['spaceship' => json_encode($raw)],
      );
    }
    return $records;
  }

  /**
   * {@inheritdoc}
   */
  public function dnsRecordTypes(): array {
    return [
      'A' => 'A',
      'AAAA' => 'AAAA',
      'CNAME' => 'CNAME',
      'MX' => 'MX',
      'TXT' => 'TXT',
      'CAA' => 'CAA',
      'NS' => 'NS',
      'ALIAS' => new TranslatableMarkup('ALIAS, a CNAME for the domain itself'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function createDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->saveDnsRecords($name, [$this->dnsItem($record) + ['ttl' => $this->dnsTtl($record)]]);
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->deleteDnsRecords($name, [$this->dnsItem($record)]);
  }

  /**
   * {@inheritdoc}
   */
  protected function updateDnsRecord(string $name, DnsRecord $record): void {
    // Matched on content, so saving it again changes its TTL.
    $this->createDnsRecord($name, $record);
  }

  /**
   * Returns a record as Spaceship takes it, without the TTL.
   *
   * @return array<string, mixed>
   *   The type, name and the fields of the type.
   */
  protected function dnsItem(DnsRecord $record): array {
    if (isset($record->extra['spaceship'])) {
      return (array) json_decode((string) $record->extra['spaceship'], TRUE);
    }
    $item = ['type' => $record->type, 'name' => $record->host];
    if ($record->type === 'CAA') {
      [$flag, $tag, $value] = array_pad(explode(' ', $record->value, 3), 3, '');
      return $item + ['flag' => (int) $flag, 'tag' => $tag, 'value' => trim($value, '"')];
    }
    $item[self::DNS_VALUE_FIELDS[$record->type] ?? 'value'] = $record->value;
    if ($record->type === 'MX') {
      $item['preference'] = (int) $record->priority;
    }
    return $item;
  }

  /**
   * Returns the TTL Spaceship takes: 60 to 3600 seconds.
   */
  protected function dnsTtl(DnsRecord $record): int {
    return max(60, min(3600, $record->ttl ?? 3600));
  }

  /**
   * {@inheritdoc}
   */
  public function getNameservers(string $name): array {
    $nameservers = $this->client()->getRegistration($name)['nameservers'] ?? [];
    // "basic" is Spaceship's own nameservers.
    if (($nameservers['provider'] ?? NULL) !== 'custom') {
      return [];
    }
    return array_values(array_map('strtolower', array_filter($nameservers['hosts'] ?? [], 'is_string')));
  }

  /**
   * {@inheritdoc}
   */
  public function setNameservers(string $name, array $nameservers): void {
    $this->client()->setNameservers($name, array_slice($nameservers, 0, 12));
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    $this->client()->setTransferLock($name, $locked);
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    $this->client()->setAutoRenew($name, $auto_renew);
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    return $this->client()->getAuthCode($name);
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
  public function saleStatus(array $current): ?string {
    $status = $current['status'] ?? NULL;
    return in_array($status, self::SALE_STATUSES, TRUE) ? $status : NULL;
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
  public function paymentLinkCurrencies(): array {
    return ['USD'];
  }

  /**
   * {@inheritdoc}
   */
  public function paymentLinkMinimum(): float {
    return 100.0;
  }

  /**
   * {@inheritdoc}
   *
   * A SafePay transaction the seller opens, for a domain listed in
   * SellerHub; the buyer confirms and pays on Spaceship. A lease to own is
   * paid as the down payment and then monthly; Spaceship takes at least 100
   * for each payment.
   */
  public function createPaymentLink(string $name, Price $price, string $buyer_email, array $options = []): PaymentLink {
    $buyer_fee = (int) ($options['buyer_fee'] ?? 0);
    $months = (int) ($options['lease_months'] ?? 0);
    $lease = $months > 0 ? [
      'type' => 'leaseToOwn',
      'ltoSettings' => [
        'downPaymentPercentage' => (int) ($options['down_payment'] ?? 0),
        'installmentPeriods' => $months,
      ],
    ] : [];
    return self::paymentLink($this->client()->createSafePay($lease + [
      'domainName' => $name,
      'initiatedBy' => 'seller',
      'basePrice' => [
        'amount' => number_format((float) $price->getNumber(), 2, '.', ''),
        'currency' => $price->getCurrencyCode(),
      ],
      'type' => 'buyNow',
      'feePercentageShare' => ['seller' => 100 - $buyer_fee, 'buyer' => $buyer_fee],
      'buyerEmail' => $buyer_email,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPaymentLink(string $id): PaymentLink {
    return self::paymentLink($this->client()->getSafePay($id));
  }

  /**
   * Maps a SafePay transaction to a payment link.
   */
  protected static function paymentLink(array $transaction): PaymentLink {
    $status = (string) ($transaction['status'] ?? '');
    return new PaymentLink(
      id: (string) ($transaction['transactionId'] ?? ''),
      url: (string) ($transaction['url']['buyer'] ?? ''),
      state: match ($status) {
        'purchased' => PaymentLink::PAID,
        'deactivated', 'expired' => PaymentLink::ENDED,
        default => PaymentLink::PENDING,
      },
      status: $status !== '' ? $status : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create a key in the Spaceship API Manager with the SellerHub read and write scopes, plus domains read for registration details, domains write for nameservers and auto-renew, and domains transfer for the transfer lock and auth code.');
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

  /**
   * {@inheritdoc}
   *
   * The file also takes the lease-to-own terms the API can't set: the term
   * from $2,000 and a 25% down payment.
   */
  public function csvHeader(string $file): array {
    return ['Domain', 'Offer Minimum', 'Buy Now Price', 'LTO Period', 'Downpayment %', 'For Sale Page'];
  }

  /**
   * {@inheritdoc}
   */
  public function csvRow(CatalogItem $item, string $file): ?array {
    $listing = $this->buildListing($item);
    if ($listing === NULL) {
      return NULL;
    }
    $price = $listing['binPrice'];
    $lease_months = $listing['binPriceEnabled'] ? $this->leaseMonthsFor($item, $this->leaseMonths($price), self::MAX_LEASE_MONTHS) : 0;
    return [
      $item->title,
      $listing['minPriceEnabled'] ? $listing['minPrice'] : '',
      $listing['binPriceEnabled'] ? $price : '',
      $lease_months,
      25,
      'on',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fallbackTerms(): array {
    return [
      'floor' => NULL,
      'minimum_offer' => $this->usualMinimumOffer(),
      'lease_months' => self::MAX_LEASE_MONTHS,
      'note' => $this->t('The lease goes out by CSV file only: the API has no setting for it. Atom premium names: the full price.'),
    ];
  }

}

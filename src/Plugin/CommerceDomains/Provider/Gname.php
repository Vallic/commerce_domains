<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Clients\GnameClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;

/**
 * Lists GNAME-registered domains as Buy It Now; manages registrations.
 *
 * Its API sets nameservers and DNS records, but not the transfer lock or
 * auto-renew, and gives an auth code only for a transfer out: those are
 * done in the GNAME account.
 *
 * A Buy It Now listing has an end date, so listings run for a year and are
 * renewed in their last month. Removing a listing takes it off sale and
 * deletes it, so the domain can be listed again later.
 *
 * Only listings on sale are read, and they show no sale: a sold listing
 * moves to GNAME's list of sold domains, which syncs don't read yet.
 */
#[CommerceDomainsProvider(
  id: 'gname',
  label: new TranslatableMarkup('GNAME'),
  description: new TranslatableMarkup('Lists domains registered at GNAME as Buy It Now, and manages their registrations, nameservers and DNS. Needs a GNAME reseller account.'),
)]
final class Gname extends MarketplacePluginBase implements DnsRecordsInterface, DomainManagementInterface {

  use DnsRecordByRecordTrait;
  use RegistrarTrait;

  protected const CREDENTIALS = [
    'app_id' => 'GNAME_APP_ID',
    'app_key' => 'GNAME_APP_KEY',
  ];

  // The nameservers of GNAME's own DNS, which domain/info also flags with
  // isbzdns. Older domains use gname-dns.com.
  protected const DNS_HOSTS = ['share-dns.com', 'share-dns.net', 'gname-dns.com'];

  // GNAME dates are Beijing time.
  protected const TIMEZONE = 'Asia/Shanghai';

  // How long a listing runs, and how close to its end it is renewed.
  protected const LISTING_DAYS = 365;

  protected const RENEW_DAYS = 30;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\GnameClient|null
   */
  protected ?GnameClient $client = NULL;

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
    // Whole dollars, with the cents dropped rather than rounded.
    $price = (int) ($item->price('USD') * $this->priceFactor($item));
    // Buy It Now has a price and nothing else.
    if ($price <= 0 || !$item->terms->buyNow) {
      return NULL;
    }
    $ends = (new \DateTimeImmutable('+' . self::LISTING_DAYS . ' days', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    return ['price' => $price, 'ends' => $ends];
  }

  /**
   * {@inheritdoc}
   */
  public function fetchListings(?array $names = NULL): array {
    $listings = [];
    foreach ($this->client()->listSales() as $sale) {
      if (!isset($sale['ym'])) {
        continue;
      }
      $listings[DomainName::normalize((string) $sale['ym'])] = [
        'id' => (int) ($sale['id'] ?? 0),
        'price' => is_numeric($sale['qian'] ?? NULL) ? self::number($sale['qian']) : NULL,
        'ends' => (string) ($sale['jssj'] ?? ''),
      ];
    }
    return $names === NULL ? $listings : array_intersect_key($listings, array_flip($names));
  }

  /**
   * {@inheritdoc}
   *
   * The end date changes every day, so it only counts once the listing is
   * close to ending, or has no end date we can read.
   */
  public function changedFields(array $wanted, array $current): array {
    $changed = parent::changedFields(array_intersect_key($wanted, ['price' => TRUE]), $current);
    $ends = self::timestamp($current['ends'] ?? NULL, self::TIMEZONE);
    if ($ends === NULL || $ends < time() + self::RENEW_DAYS * 86400) {
      $changed[] = 'ends';
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    if ($field === 'price' && (is_int($value) || is_float($value))) {
      return $this->formatMoney($value);
    }
    if ($field === 'ends' && is_string($value)) {
      return $value === '' ? 'none' : $value;
    }
    return parent::describeValue($field, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function saveListings(array $listings, array $current): array {
    $client = $this->client();
    return $client->eachDomain(array_keys($listings), function (string $name) use ($client, $listings, $current): void {
      $client->saveSale($name, !isset($current[$name]), (float) $listings[$name]['price'], $listings[$name]['ends']);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteListings(array $names): array {
    $client = $this->client();
    $listings = $this->fetchListings($names);
    // Names that are no longer listed need nothing.
    return $client->eachDomain(array_keys($listings), fn(string $name) => $client->removeSale($listings[$name]['id']));
  }

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    return $this->client()->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['ym'])) {
      return NULL;
    }
    $status = $record['ztstr'] ?? $record['zt'] ?? NULL;
    return new Registration(
      name: DomainName::normalize((string) $record['ym']),
      expires: self::timestamp($record['dqsj'] ?? NULL, self::TIMEZONE),
      autoRenew: isset($record['zdxf']) ? (string) $record['zdxf'] === '1' : NULL,
      status: is_scalar($status) ? strtolower((string) $status) : NULL,
      registered: self::timestamp($record['zcsj'] ?? NULL, self::TIMEZONE),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('API access needs a GNAME reseller account; the app ID and key are under User Center → Reseller → API Settings.');
  }

  /**
   * {@inheritdoc}
   *
   * GNAME's DNS answers on its own nameservers (share-dns.com and
   * share-dns.net). A record switched off there comes marked so, and stays
   * as it is.
   */
  public function getDnsRecords(string $name): ?array {
    if (!$this->usesOwnDns($this->client()->getDomainInfo($name))) {
      return NULL;
    }
    $records = [];
    foreach ($this->client()->listResolutions($name) as $item) {
      $type = strtoupper((string) ($item['lx'] ?? ''));
      $records[] = new DnsRecord(
        type: $type,
        host: DnsRecord::relativeHost((string) ($item['zjt'] ?? '@'), $name),
        value: (string) ($item['jxz'] ?? ''),
        priority: $type === 'MX' && isset($item['mx']) ? (int) $item['mx'] : NULL,
        ttl: isset($item['ttl']) && is_numeric($item['ttl']) ? (int) $item['ttl'] : NULL,
        id: isset($item['id']) ? (string) $item['id'] : NULL,
        extra: (string) ($item['zt'] ?? '1') === '2' ? ['off' => '1'] : [],
      );
    }
    return $records;
  }

  /**
   * Whether a domain's details show it on GNAME's own DNS.
   */
  protected function usesOwnDns(array $info): bool {
    if (isset($info['isbzdns']) && (string) $info['isbzdns'] === '1') {
      return TRUE;
    }
    foreach (self::splitNameservers((string) ($info['ymdns'] ?? '')) as $host) {
      foreach (self::DNS_HOSTS as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Returns the nameservers in a comma-separated list, in lowercase.
   *
   * @return string[]
   *   The host names.
   */
  protected static function splitNameservers(string $list): array {
    return array_values(array_filter(array_map(fn(string $host) => rtrim(strtolower(trim($host)), '.'), explode(',', $list))));
  }

  /**
   * {@inheritdoc}
   */
  public function getNameservers(string $name): array {
    return self::splitNameservers((string) ($this->client()->getDomainInfo($name)['ymdns'] ?? ''));
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
    throw new ProviderException('The GNAME API cannot change the transfer lock; change it in the GNAME account.');
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    throw new ProviderException('The GNAME API cannot change auto-renew; change it in the GNAME account.');
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    throw new ProviderException('The GNAME API gives auth codes only for a transfer out; get one in the GNAME account.');
  }

  /**
   * {@inheritdoc}
   *
   * The types GNAME's API documents.
   */
  public function dnsRecordTypes(): array {
    return [
      'A' => 'A',
      'CNAME' => 'CNAME',
      'MX' => 'MX',
      'TXT' => 'TXT',
      'URL' => new TranslatableMarkup('URL forward'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function createDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->addResolution($name, $this->dnsFields($record));
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->deleteResolution($name, (string) $record->id);
  }

  /**
   * {@inheritdoc}
   */
  protected function updateDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->editResolution($name, (string) $record->id, $this->dnsFields($record));
  }

  /**
   * {@inheritdoc}
   */
  protected function dnsRecordProblem(DnsRecord $record): ?string {
    if ($record->priority !== NULL && ($record->priority < 0 || $record->priority > 50)) {
      return sprintf('GNAME takes MX priorities from 0 to 50, not %d.', $record->priority);
    }
    return NULL;
  }

  /**
   * Returns a record as GNAME takes it, on the default route.
   *
   * @return array<string, string|int>
   *   The lx, zj, jlz, mx (0 to 50), ttl (600 by default) and xl.
   */
  protected function dnsFields(DnsRecord $record): array {
    return [
      'lx' => $record->type,
      'zj' => $record->host,
      'jlz' => $record->value,
      'mx' => $record->priority ?? 0,
      'ttl' => max(1, min(86400, $record->ttl ?? 600)),
      'xl' => '0',
    ];
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): GnameClient {
    $this->assertReady();
    return $this->client ??= new GnameClient($this->httpClient, (string) $this->configuration['app_id'], (string) $this->configuration['app_key']);
  }

}

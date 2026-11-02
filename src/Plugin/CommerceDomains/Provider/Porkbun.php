<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Drupal\commerce_price\Price;

/**
 * Reads registrations from a Porkbun account, and manages them.
 *
 * The API has no commands for the transfer lock or the auth code; those stay
 * in the Porkbun account.
 */
#[CommerceDomainsProvider(
  id: 'porkbun',
  label: new TranslatableMarkup('Porkbun'),
  description: new TranslatableMarkup('Reads and manages registrations through the Porkbun API.'),
)]
final class Porkbun extends ProviderPluginBase implements DomainManagementInterface, DnsRecordsInterface, RenewalPricesInterface {

  use DnsRecordByRecordTrait;
  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_key' => 'PORKBUN_API_KEY',
    'secret_key' => 'PORKBUN_SECRET_KEY',
  ];

  // 20 requests every 2 seconds.
  protected const REQUESTS_PER_MINUTE = 600;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\PorkbunClient|null
   */
  protected ?PorkbunClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    return self::fewNames($names) ? array_filter(array_map($client->getDomain(...), array_values($names))) : $client->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['domain'])) {
      return NULL;
    }
    // Flags are 1 or 0; older replies sent "1".
    $flag = fn(string $key) => isset($record[$key]) ? (bool) (int) $record[$key] : NULL;
    return new Registration(
      name: DomainName::normalize((string) $record['domain']),
      expires: self::timestamp($record['expireDate'] ?? NULL),
      locked: $flag('securityLock'),
      autoRenew: $flag('autoRenew'),
      status: isset($record['status']) ? strtolower((string) $record['status']) : NULL,
    );
  }

  /**
   * {@inheritdoc}
   *
   * Porkbun keeps records for a domain whose nameservers are elsewhere, and
   * takes changes to them, so the nameservers decide whether they apply.
   */
  public function getDnsRecords(string $name): ?array {
    $porkbun = array_filter($this->getNameservers($name), static fn(string $host) => str_ends_with($host, '.porkbun.com'));
    if (!$porkbun) {
      return NULL;
    }
    $records = [];
    foreach ($this->client()->retrieveDns($name) as $record) {
      $type = strtoupper((string) ($record['type'] ?? ''));
      $priority = isset($record['prio']) && is_numeric($record['prio']) ? (int) $record['prio'] : NULL;
      $records[] = new DnsRecord(
        type: $type,
        host: DnsRecord::relativeHost((string) ($record['name'] ?? ''), $name),
        value: (string) ($record['content'] ?? ''),
        priority: in_array($type, ['MX', 'SRV'], TRUE) ? $priority : NULL,
        ttl: isset($record['ttl']) && is_numeric($record['ttl']) ? (int) $record['ttl'] : NULL,
        id: isset($record['id']) ? (string) $record['id'] : NULL,
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
      'SRV' => 'SRV',
      'CAA' => 'CAA',
      'NS' => 'NS',
      'ALIAS' => new TranslatableMarkup('ALIAS, a CNAME for the domain itself'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function createDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->createDns($name, $this->dnsFields($record), bin2hex(random_bytes(16)));
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->deleteDns($name, (string) $record->id);
  }

  /**
   * {@inheritdoc}
   */
  protected function updateDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->editDns($name, (string) $record->id, $this->dnsFields($record));
  }

  /**
   * Returns a record as Porkbun takes it.
   *
   * @return array<string, string>
   *   The name, relative and empty for the domain itself, type, content,
   *   and ttl and prio where set; Porkbun's minimum TTL, 600, otherwise.
   */
  protected function dnsFields(DnsRecord $record): array {
    $fields = [
      'name' => $record->host === '@' ? '' : $record->host,
      'type' => $record->type,
      'content' => $record->value,
      'ttl' => (string) max(600, $record->ttl ?? 600),
    ];
    if ($record->priority !== NULL) {
      $fields['prio'] = (string) $record->priority;
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getNameservers(string $name): array {
    // Registries return the set in any order, so sort it for a stable form.
    $hosts = array_map('strtolower', $this->client()->getNameservers($name));
    sort($hosts);
    return $hosts;
  }

  /**
   * {@inheritdoc}
   */
  public function setNameservers(string $name, array $nameservers): void {
    $this->client()->updateNameservers($name, $nameservers);
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    throw new ProviderException('The Porkbun API cannot change the transfer lock; change it in the Porkbun account.');
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    $this->client()->updateAutoRenew($name, $auto_renew);
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    throw new ProviderException('The Porkbun API cannot give auth codes; get one in the Porkbun account.');
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create the API key and secret at porkbun.com/account/api, and turn on API access for each domain; reading registrations needs only the read scope, and managing them the write scope.');
  }

  /**
   * {@inheritdoc}
   */
  public function renewalPrices(array $tlds = []): array {
    return array_map(fn(string $number) => new Price($number, 'USD'), $this->client()->renewalPrices($tlds));
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): PorkbunClient {
    $this->assertReady();
    return $this->client ??= new PorkbunClient($this->httpClient, (string) $this->configuration['api_key'], (string) $this->configuration['secret_key'], $this->requestsPerMinute());
  }

}

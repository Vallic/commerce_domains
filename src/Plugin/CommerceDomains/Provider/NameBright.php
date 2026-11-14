<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\NameBrightClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from a NameBright account, and manages them.
 */
#[CommerceDomainsProvider(
  id: 'namebright',
  label: new TranslatableMarkup('NameBright'),
  description: new TranslatableMarkup('Reads and manages registrations through the NameBright API.'),
)]
final class NameBright extends ProviderPluginBase implements DomainManagementInterface, DnsRecordsInterface {

  use RegistrarTrait;
  use DnsRecordByRecordTrait;

  // NameBright DNS: the nameservers its records apply on.
  protected const DNS_HOST = 'namebrightdns.com';

  // Each type's list in the host records, and the field holding its value.
  protected const DNS_TYPES = [
    'A' => ['aRecords', 'ipV4Address'],
    'AAAA' => ['aaaaRecords', 'ipV6Address'],
    'CNAME' => ['cnameRecords', 'redirectDomain'],
    'MX' => ['mxRecords', 'mailServer'],
    'TXT' => ['txtRecords', 'textRecord'],
  ];

  protected const CREDENTIALS = [
    'client_id' => 'NAMEBRIGHT_CLIENT_ID',
    'client_secret' => 'NAMEBRIGHT_CLIENT_SECRET',
  ];

  // 30 requests every 30 seconds.
  protected const REQUESTS_PER_MINUTE = 60;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\NameBrightClient|null
   */
  protected ?NameBrightClient $client = NULL;

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
    if (!isset($record['domainName'])) {
      return NULL;
    }
    return new Registration(
      name: DomainName::normalize((string) $record['domainName']),
      expires: self::timestamp($record['expirationDate'] ?? NULL),
      locked: isset($record['locked']) ? (bool) $record['locked'] : NULL,
      autoRenew: isset($record['autoRenew']) ? (bool) $record['autoRenew'] : NULL,
      status: isset($record['status']) ? (string) $record['status'] : NULL,
    );
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
   *
   * NameBright DNS answers on ns1 and ns2.namebrightdns.com; with other
   * nameservers, the records it keeps do not apply. Its URL forwarding is a
   * CNAME to NameBright's forwarder, with the address set in the NameBright
   * account, as the API has none. SRV records are kept as they are.
   */
  public function getDnsRecords(string $name): ?array {
    $ours = array_filter($this->getNameservers($name), static fn(string $host) => $host === self::DNS_HOST || str_ends_with($host, '.' . self::DNS_HOST));
    if (!$ours) {
      return NULL;
    }
    $lists = $this->client()->getHostRecords($name);
    $records = [];
    foreach (self::DNS_TYPES as $type => [$list, $field]) {
      foreach (is_array($lists[$list] ?? NULL) ? $lists[$list] : [] as $item) {
        if (!is_array($item) || !isset($item[$field])) {
          continue;
        }
        $records[] = new DnsRecord(
          type: $type,
          host: DnsRecord::relativeHost((string) ($item['subdomain'] ?? '@'), $name),
          value: (string) $item[$field],
          priority: $type === 'MX' && isset($item['priority']) && is_numeric($item['priority']) ? (int) $item['priority'] : NULL,
          id: isset($item['recordId']) ? (string) $item['recordId'] : NULL,
        );
      }
    }
    foreach (is_array($lists['srvRecords'] ?? NULL) ? $lists['srvRecords'] : [] as $item) {
      if (!is_array($item) || !isset($item['service'], $item['protocol'], $item['target'])) {
        continue;
      }
      $sub = (string) ($item['subdomain'] ?? '');
      $records[] = new DnsRecord(
        type: 'SRV',
        host: '_' . ltrim((string) $item['service'], '_') . '._' . ltrim((string) $item['protocol'], '_') . ($sub !== '' && $sub !== '@' ? '.' . DnsRecord::relativeHost($sub, $name) : ''),
        value: sprintf('%d %d %s', (int) ($item['weight'] ?? 0), (int) ($item['port'] ?? 0), $item['target']),
        priority: isset($item['priority']) ? (int) $item['priority'] : NULL,
        id: isset($item['recordId']) ? (string) $item['recordId'] : NULL,
      );
    }
    return $records;
  }

  /**
   * {@inheritdoc}
   *
   * The types the API creates, but SRV: those there are kept as they are.
   */
  public function dnsRecordTypes(): array {
    return array_combine(array_keys(self::DNS_TYPES), array_keys(self::DNS_TYPES));
  }

  /**
   * {@inheritdoc}
   */
  protected function dnsRecordProblem(DnsRecord $record): ?string {
    if ($record->ttl !== NULL) {
      return (string) $this->t("NameBright's API sets no TTL: leave it empty.");
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function createDnsRecord(string $name, DnsRecord $record): void {
    [, $field] = self::DNS_TYPES[$record->type] ?? throw new ProviderException(sprintf('NameBright DNS takes no new %s records.', $record->type));
    $value = in_array($record->type, ['CNAME', 'MX'], TRUE) ? rtrim($record->value, '.') : $record->value;
    $fields = ['subdomain' => $record->host, $field => $value];
    if ($record->type === 'MX') {
      $fields['priority'] = (int) $record->priority;
    }
    $this->client()->addHostRecord($name, strtolower($record->type), $fields);
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteDnsRecord(string $name, DnsRecord $record): void {
    if ($record->id === NULL) {
      throw new ProviderException(sprintf('The %s record of %s has no NameBright ID to delete it by.', $record->type, $record->fqdn($name)));
    }
    $this->client()->deleteHostRecord($name, strtolower($record->type), $record->id);
  }

  /**
   * {@inheritdoc}
   */
  protected function updateDnsRecord(string $name, DnsRecord $record): void {
    throw new ProviderException("NameBright's API sets no TTL.");
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    $this->client()->updateDomain($name, ['locked' => $locked]);
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    $this->client()->updateDomain($name, ['autoRenew' => $auto_renew]);
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    $code = $this->client()->getDomain($name)['authCode'] ?? NULL;
    if (!is_string($code) || $code === '') {
      throw new ProviderException("NameBright returned no auth code for $name.");
    }
    return $code;
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t("Create an API key under My Account → API Management once NameBright approves API access, and allow the server's IP address on it.");
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): NameBrightClient {
    $this->assertReady();
    return $this->client ??= new NameBrightClient($this->httpClient, (string) $this->configuration['client_id'], (string) $this->configuration['client_secret'], $this->requestsPerMinute());
  }

}

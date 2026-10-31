<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\GoDaddyClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from a GoDaddy account.
 */
#[CommerceDomainsProvider(
  id: 'godaddy',
  label: new TranslatableMarkup('GoDaddy'),
  description: new TranslatableMarkup('Reads registrations through the GoDaddy Domains API.'),
)]
final class GoDaddy extends ProviderPluginBase implements DnsRecordsInterface {

  use DnsRecordByRecordTrait;
  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_token' => 'GODADDY_API_TOKEN',
  ];

  protected const REQUESTS_PER_MINUTE = 60;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\GoDaddyClient|null
   */
  protected ?GoDaddyClient $client = NULL;

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
    return new Registration(
      name: DomainName::normalize((string) $record['domain']),
      expires: self::timestamp($record['expiresAt'] ?? NULL),
      locked: isset($record['transferLock']) ? (bool) $record['transferLock'] : NULL,
      autoRenew: isset($record['autoRenew']) ? (bool) $record['autoRenew'] : NULL,
      status: isset($record['status']) ? strtolower((string) $record['status']) : NULL,
      registered: self::timestamp($record['createdAt'] ?? NULL),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create a personal access token at developer.godaddy.com/personal-access-token with the domains.domain:read permission. Tokens expire after a year at most.');
  }

  /**
   * {@inheritdoc}
   *
   * GoDaddy's DNS answers on its domaincontrol.com nameservers. Its SOA and
   * the domain's own NS records are its to manage, so they are left out.
   * SRV records, whose host GoDaddy splits into service and protocol, are
   * kept as read, to be deleted as they are.
   */
  public function getDnsRecords(string $name): ?array {
    $nameservers = $this->client()->getDomain($name)['nameServers'] ?? [];
    $ours = array_filter(is_array($nameservers) ? $nameservers : [], static fn($host) => is_string($host) && str_ends_with(strtolower(rtrim($host, '.')), '.domaincontrol.com'));
    if (!$ours) {
      return NULL;
    }
    $records = [];
    foreach ($this->client()->listDnsRecords($name) as $item) {
      $type = strtoupper((string) ($item['type'] ?? ''));
      $host = DnsRecord::relativeHost((string) ($item['name'] ?? '@'), $name);
      if ($type === 'SOA' || ($type === 'NS' && $host === '@')) {
        continue;
      }
      $value = (string) ($item['data'] ?? '');
      $extra = [];
      if ($type === 'CAA') {
        $value = sprintf('%d %s "%s"', $item['flag'] ?? 0, $item['tag'] ?? 'issue', $value);
      }
      elseif ($type === 'SRV') {
        $extra = ['godaddy' => json_encode(array_diff_key($item, ['recordId' => TRUE, 'ttl' => TRUE]))];
        $value = sprintf('%d %d %s', $item['weight'] ?? 0, $item['port'] ?? 0, $value);
        $host = trim(($item['service'] ?? '') . '.' . ($item['protocol'] ?? '') . ($host === '@' ? '' : '.' . $host), '.');
      }
      $records[] = new DnsRecord(
        type: $type,
        host: $host,
        value: $value,
        priority: in_array($type, ['MX', 'SRV'], TRUE) && isset($item['priority']) ? (int) $item['priority'] : NULL,
        ttl: isset($item['ttl']) ? (int) $item['ttl'] : NULL,
        id: isset($item['recordId']) ? (string) $item['recordId'] : NULL,
        extra: $extra,
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
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function createDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->createDnsRecord($name, $this->dnsFields($record));
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->deleteDnsRecord($name, (string) $record->id);
  }

  /**
   * {@inheritdoc}
   */
  protected function updateDnsRecord(string $name, DnsRecord $record): void {
    $this->client()->replaceDnsRecord($name, (string) $record->id, $this->dnsFields($record));
  }

  /**
   * Returns a record as GoDaddy takes it.
   *
   * @return array<string, mixed>
   *   The name, type, data and ttl (600 to 86400 seconds), and the fields
   *   of its type.
   */
  protected function dnsFields(DnsRecord $record): array {
    $ttl = max(600, min(86400, $record->ttl ?? 3600));
    if (isset($record->extra['godaddy'])) {
      return ['ttl' => $ttl] + (array) json_decode((string) $record->extra['godaddy'], TRUE);
    }
    $fields = ['name' => $record->host, 'type' => $record->type, 'data' => $record->value, 'ttl' => $ttl];
    if ($record->type === 'CAA') {
      [$flag, $tag, $value] = array_pad(explode(' ', $record->value, 3), 3, '');
      $fields = ['data' => trim($value, '"'), 'flag' => (int) $flag, 'tag' => $tag] + $fields;
    }
    if ($record->priority !== NULL) {
      $fields['priority'] = $record->priority;
    }
    return $fields;
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): GoDaddyClient {
    $this->assertReady();
    return $this->client ??= new GoDaddyClient($this->httpClient, (string) $this->configuration['api_token'], $this->requestsPerMinute());
  }

}

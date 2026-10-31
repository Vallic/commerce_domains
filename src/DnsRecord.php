<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * One DNS record of a domain, as a registrar's DNS holds it.
 *
 * Hosts are relative to the domain, "@" for the domain itself, so records
 * read from one registrar compare with those another one, or the owner,
 * wrote. Types are upper case; besides the standard ones a registrar may
 * have its own, such as Dynadot's URL forwarding, kept as they come so a
 * zone written back loses nothing.
 */
final class DnsRecord {

  // The record types any registrar's DNS takes, with what each holds.
  public const TYPES = [
    'A' => 'IPv4 address',
    'AAAA' => 'IPv6 address',
    'CNAME' => 'Another host name',
    'MX' => 'Mail server, with a priority',
    'TXT' => 'Text, such as a verification or SPF',
    'SRV' => 'Service: weight, port and target, with a priority',
    'CAA' => 'Certificate authority allowed to issue',
    'NS' => 'Nameserver for a subdomain',
  ];

  /**
   * Constructs a DnsRecord.
   *
   * @param string $type
   *   The type, upper case.
   * @param string $host
   *   The host relative to the domain: "@" for the domain itself, "www",
   *   "_dmarc", "*" and so on.
   * @param string $value
   *   The value: an address, a host name, text. For SRV, "weight port
   *   target"; for CAA, "flags tag value".
   * @param int|null $priority
   *   The priority of an MX or SRV record.
   * @param int|null $ttl
   *   Seconds; NULL for the registrar's default.
   * @param string|null $id
   *   The registrar's ID of the record, where its API has one.
   * @param array<string, scalar> $extra
   *   What else a registrar keeps with the record, such as the redirect
   *   kind of a URL forward, written back as it was read.
   */
  public function __construct(
    public readonly string $type,
    public readonly string $host,
    public readonly string $value,
    public readonly ?int $priority = NULL,
    public readonly ?int $ttl = NULL,
    public readonly ?string $id = NULL,
    public readonly array $extra = [],
  ) {}

  /**
   * Creates a record from entered or stored values, normalized.
   *
   * @param array<string, mixed> $values
   *   Keys type, host, value, priority, ttl, id and extra.
   */
  public static function fromArray(array $values): self {
    $int = fn(string $key): ?int => isset($values[$key]) && $values[$key] !== '' ? (int) $values[$key] : NULL;
    $host = strtolower(trim((string) ($values['host'] ?? '')));
    $host = rtrim($host, '.');
    return new self(
      type: strtoupper(trim((string) ($values['type'] ?? ''))),
      host: $host === '' ? '@' : $host,
      value: trim((string) ($values['value'] ?? '')),
      priority: $int('priority'),
      ttl: $int('ttl'),
      id: isset($values['id']) && $values['id'] !== '' ? (string) $values['id'] : NULL,
      extra: is_array($values['extra'] ?? NULL) ? $values['extra'] : [],
    );
  }

  /**
   * Returns the record as stored values.
   *
   * @return array<string, mixed>
   *   Keys type, host, value, priority, ttl, id and extra.
   */
  public function toArray(): array {
    return [
      'type' => $this->type,
      'host' => $this->host,
      'value' => $this->value,
      'priority' => $this->priority,
      'ttl' => $this->ttl,
      'id' => $this->id,
      'extra' => $this->extra,
    ];
  }

  /**
   * Returns what makes the record the same record, wherever it was read.
   *
   * Type, host, value, priority and what the registrar keeps with it; not
   * the TTL or the registrar's ID.
   */
  public function key(): string {
    $extra = $this->extra;
    ksort($extra);
    $value = in_array($this->type, ['CNAME', 'MX', 'NS'], TRUE) ? rtrim(strtolower($this->value), '.') : $this->value;
    return implode("\x1F", [$this->type, $this->host, $value, (string) $this->priority, http_build_query($extra)]);
  }

  /**
   * Returns the record with another TTL.
   */
  public function withTtl(?int $ttl): self {
    return new self($this->type, $this->host, $this->value, $this->priority, $ttl, $this->id, $this->extra);
  }

  /**
   * Returns the host as a full name under the domain.
   */
  public function fqdn(string $domain): string {
    return $this->host === '@' ? $domain : $this->host . '.' . $domain;
  }

  /**
   * Returns a host relative to the domain, from a full name or a relative one.
   *
   * Registrars give hosts either way: "www.example.com" or "www", and the
   * domain itself as the full name, "", or "@".
   */
  public static function relativeHost(string $host, string $domain): string {
    $host = rtrim(strtolower(trim($host)), '.');
    $domain = strtolower($domain);
    if ($host === '' || $host === '@' || $host === $domain) {
      return '@';
    }
    if (str_ends_with($host, '.' . $domain)) {
      return substr($host, 0, -strlen($domain) - 1);
    }
    return $host;
  }

  /**
   * Checks the record can be written.
   *
   * @return string[]
   *   What is wrong, empty when nothing is.
   */
  public function problems(): array {
    $problems = [];
    if ($this->host !== '@' && !preg_match('/^(\*\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$|^\*$/', $this->host)) {
      $problems[] = sprintf('"%s" is not a host name.', $this->host);
    }
    if ($this->value === '') {
      $problems[] = 'The value is empty.';
    }
    $host_name = '/^([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}\.?$/i';
    $ok = match ($this->type) {
      'A' => filter_var($this->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE,
      'AAAA' => filter_var($this->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE,
      'CNAME', 'NS' => (bool) preg_match($host_name, $this->value),
      'MX' => (bool) preg_match($host_name, $this->value) || $this->value === '.',
      'SRV' => (bool) preg_match('/^\d+ \d+ \S+$/', $this->value),
      'CAA' => (bool) preg_match('/^\d+ (issue|issuewild|iodef) \S.*$/i', $this->value),
      default => TRUE,
    };
    if (!$ok && $this->value !== '') {
      $problems[] = sprintf('"%s" is not a valid %s value.', $this->value, $this->type);
    }
    if (in_array($this->type, ['MX', 'SRV'], TRUE) && $this->priority === NULL) {
      $problems[] = sprintf('%s records need a priority.', $this->type);
    }
    if ($this->type === 'CNAME' && $this->host === '@') {
      $problems[] = 'The domain itself cannot be a CNAME; use the subdomain www, or an A record.';
    }
    return $problems;
  }

  /**
   * Compares two zones: what goes, what comes and what stays.
   *
   * @param self[] $current
   *   The records there now.
   * @param self[] $wanted
   *   The records there should be.
   *
   * @return array{delete: self[], create: self[], keep: self[], ttl: self[]}
   *   Records to delete and create; those to keep, as they are now; and of
   *   those, the ones whose TTL changes, as wanted.
   */
  public static function diff(array $current, array $wanted): array {
    $remaining = [];
    foreach ($wanted as $record) {
      $remaining[$record->key()][] = $record;
    }
    $result = ['delete' => [], 'create' => [], 'keep' => [], 'ttl' => []];
    foreach ($current as $record) {
      $key = $record->key();
      if (!empty($remaining[$key])) {
        $match = array_shift($remaining[$key]);
        $result['keep'][] = $record;
        if ($match->ttl !== NULL && $match->ttl !== $record->ttl) {
          $result['ttl'][] = new self($record->type, $record->host, $record->value, $record->priority, $match->ttl, $record->id, $record->extra);
        }
      }
      else {
        $result['delete'][] = $record;
      }
    }
    foreach ($remaining as $records) {
      array_push($result['create'], ...$records);
    }
    return $result;
  }

}

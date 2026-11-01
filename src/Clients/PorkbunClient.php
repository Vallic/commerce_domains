<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the Porkbun API, version 3.
 *
 * @see https://porkbun.com/api/json/v3/documentation
 */
final class PorkbunClient extends ApiClient {

  protected const NETWORK = 'Porkbun';

  protected const BASE_URI = 'https://api.porkbun.com/api/json/v3/';

  // The most domains one listAll call returns.
  protected const PAGE_SIZE = 1000;

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiKey,
    protected string $secretKey,
    int $requestsPerMinute = 600,
  ) {
    parent::__construct($httpClient, $requestsPerMinute);
  }

  /**
   * Fetches every domain in the account.
   *
   * @return array[]
   *   Domain records as the API returns them.
   */
  public function listDomains(): array {
    $domains = [];
    do {
      $response = $this->call('POST', 'domain/listAll', [RequestOptions::JSON => ['start' => count($domains)]]);
      $batch = $response['domains'] ?? [];
      array_push($domains, ...$batch);
    } while (count($batch) >= self::PAGE_SIZE);
    return $domains;
  }

  /**
   * Fetches one domain.
   *
   * @return array|null
   *   The domain record, or NULL when it is not in the account.
   */
  public function getDomain(string $name): ?array {
    $response = $this->call('GET', 'domain/get/' . rawurlencode($name), [], [404]);
    return is_array($response['domain'] ?? NULL) ? $response['domain'] : NULL;
  }

  /**
   * Fetches a domain's nameservers, in no particular order.
   *
   * @return string[]
   *   The host names.
   */
  public function getNameservers(string $name): array {
    $hosts = $this->call('GET', 'domain/getNs/' . rawurlencode($name))['ns'] ?? [];
    return array_values(array_filter(is_array($hosts) ? $hosts : [], 'is_string'));
  }

  /**
   * Replaces a domain's nameservers.
   *
   * @param string $name
   *   The domain.
   * @param string[] $nameservers
   *   The host names.
   */
  public function updateNameservers(string $name, array $nameservers): void {
    $this->call('POST', 'domain/updateNs/' . rawurlencode($name), [RequestOptions::JSON => ['ns' => array_values($nameservers)]]);
  }

  /**
   * Fetches a domain's DNS records.
   *
   * @return array<int, array<string, mixed>>
   *   Records with id, name (the full host name), type, content, ttl and
   *   prio, as strings; Porkbun's own NS and SOA records are left out.
   */
  public function retrieveDns(string $name): array {
    $records = $this->call('GET', 'dns/retrieve/' . rawurlencode($name))['records'] ?? [];
    return array_values(array_filter(is_array($records) ? $records : [], 'is_array'));
  }

  /**
   * Creates a DNS record.
   *
   * @param string $name
   *   The domain.
   * @param array<string, string> $record
   *   The name (relative, empty for the domain itself), type, content, ttl
   *   and prio.
   * @param string $key
   *   An idempotency key, so a retried request creates the record once.
   */
  public function createDns(string $name, array $record, string $key): void {
    $this->call('POST', 'dns/create/' . rawurlencode($name), [
      RequestOptions::JSON => $record,
      RequestOptions::HEADERS => ['Idempotency-Key' => $key],
    ]);
  }

  /**
   * Changes a DNS record.
   *
   * @param string $name
   *   The domain.
   * @param string $id
   *   The record's ID.
   * @param array<string, string> $record
   *   Its name, type, content, ttl and prio.
   */
  public function editDns(string $name, string $id, array $record): void {
    $this->call('POST', 'dns/edit/' . rawurlencode($name) . '/' . rawurlencode($id), [RequestOptions::JSON => $record]);
  }

  /**
   * Deletes a DNS record.
   */
  public function deleteDns(string $name, string $id): void {
    $this->call('POST', 'dns/delete/' . rawurlencode($name) . '/' . rawurlencode($id));
  }

  /**
   * Turns automatic renewal on or off.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function updateAutoRenew(string $name, bool $enabled): void {
    $response = $this->call('POST', 'domain/updateAutoRenew/' . rawurlencode($name), [RequestOptions::JSON => ['status' => $enabled ? 'on' : 'off']]);
    // The outcome is per domain.
    $result = $response['results'][$name] ?? NULL;
    if (is_array($result) && ($result['status'] ?? NULL) === 'ERROR') {
      throw new ProviderException($this->redact(sprintf('%s updateAutoRenew %s: %s', static::NETWORK, $name, $result['message'] ?? 'refused')));
    }
  }

  /**
   * Fetches Porkbun's public price list: a year's renewal, in US dollars.
   *
   * Needs no API key, so a client made without one can read it.
   *
   * @param string[] $tlds
   *   The extensions to price, e.g. com and co.uk; empty for all of them.
   *
   * @return array<string, string>
   *   Renewal prices keyed by extension, for those Porkbun sells.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function renewalPrices(array $tlds = []): array {
    $query = $tlds ? [RequestOptions::QUERY => ['tlds' => implode(',', $tlds)]] : [];
    $response = $this->call('GET', 'pricing/get', $query);
    $prices = [];
    foreach ((array) ($response['pricing'] ?? []) as $tld => $price) {
      $renewal = is_array($price) ? ($price['renewal'] ?? NULL) : NULL;
      if (is_numeric($renewal) && (float) $renewal > 0) {
        $prices[strtolower((string) $tld)] = (string) $renewal;
      }
    }
    return $prices;
  }

  /**
   * Sends a request, failing on an error status in the body too.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function call(string $method, string $path, array $options = [], array $ok = []): array {
    $response = $this->request($method, $path, $options, $ok);
    if (($response['status'] ?? NULL) === 'ERROR') {
      throw new ProviderException($this->redact(sprintf('%s %s %s: %s', static::NETWORK, $method, $path, $this->describeError(200, $response))));
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  protected function describeError(int $status, array $body): string {
    $message = parent::describeError($status, $body);
    return is_string($body['code'] ?? NULL) ? "$message ({$body['code']})" : $message;
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    // The price list is public: a client without keys sends none.
    if ($this->apiKey === '') {
      return $options;
    }
    // Headers rather than body fields, so GET requests work too.
    $options[RequestOptions::HEADERS]['X-API-Key'] = $this->apiKey;
    $options[RequestOptions::HEADERS]['X-Secret-API-Key'] = $this->secretKey;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->secretKey];
  }

}

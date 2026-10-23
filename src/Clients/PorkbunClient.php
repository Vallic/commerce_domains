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

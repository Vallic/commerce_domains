<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the IONOS Domains API.
 *
 * @see https://developer.hosting.ionos.com/docs/domains
 */
final class IonosClient extends ApiClient {

  protected const NETWORK = 'IONOS';

  protected const BASE_URI = 'https://api.hosting.ionos.com/domains/';

  protected const PAGE_SIZE = 100;

  public function __construct(
    ClientInterface $httpClient,
    // The public prefix and the secret, joined by a dot.
    protected string $apiKey,
    int $requestsPerMinute = 20,
  ) {
    parent::__construct($httpClient, $requestsPerMinute);
  }

  /**
   * Fetches every domain in the account, with its provisioning status.
   *
   * @return array[]
   *   Domain records as the API returns them: id, name and status, without
   *   the transfer lock or auto-renew flag.
   */
  public function listDomains(): array {
    $domains = [];
    $offset = 0;
    do {
      $page = $this->request('GET', 'v1/domainitems', [
        RequestOptions::QUERY => ['offset' => $offset, 'limit' => self::PAGE_SIZE, 'includeDomainStatus' => 'true'],
      ]);
      $batch = $page['domains'] ?? [];
      array_push($domains, ...$batch);
      $offset += count($batch);
    } while ($batch && $offset < (int) ($page['count'] ?? 0));
    return $domains;
  }

  /**
   * Fetches one domain in full.
   *
   * @return array|null
   *   The domain record, or NULL when it is not in the account.
   */
  public function getDomain(string $id): ?array {
    return $this->request('GET', 'v1/domainitems/' . rawurlencode($id), [
      RequestOptions::QUERY => ['includeDomainStatus' => 'true'],
    ], [404]) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function describeError(int $status, array $body): string {
    // Errors come as a list of {code, message}.
    $error = array_is_list($body) && is_array($body[0] ?? NULL) ? $body[0] : $body;
    $message = $error['message'] ?? $error['code'] ?? NULL;
    return is_string($message) && $message !== '' ? "HTTP $status: $message" : "HTTP $status";
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['X-API-Key'] = $this->apiKey;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->apiKey];
  }

}

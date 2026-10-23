<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the NameBright API.
 *
 * Authenticates with an OAuth2 client-credentials token. Requests must come
 * from an IP address allowed for the API key.
 *
 * @see https://api.namebright.com/documentation/index.html
 */
final class NameBrightClient extends ApiClient {

  use ClientCredentialsTrait;

  protected const NETWORK = 'NameBright';

  protected const BASE_URI = 'https://api.namebright.com/';

  protected const TOKEN_PATH = 'auth/token';

  // The API's largest page.
  protected const PAGE_SIZE = 1000;

  public function __construct(
    ClientInterface $httpClient,
    protected string $clientId,
    protected string $clientSecret,
    int $requestsPerMinute = 60,
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
    for ($page = 1;; $page++) {
      $response = $this->request('GET', 'domains', [
        RequestOptions::QUERY => ['page' => $page, 'size' => self::PAGE_SIZE],
      ]);
      $batch = $response['domains'] ?? [];
      array_push($domains, ...$batch);
      if (count($batch) < self::PAGE_SIZE || count($domains) >= (int) ($response['resultsTotal'] ?? 0)) {
        return $domains;
      }
    }
  }

  /**
   * Fetches one domain.
   *
   * @return array|null
   *   The domain record, or NULL when it is not in the account.
   */
  public function getDomain(string $name): ?array {
    return $this->request('GET', 'domains/' . rawurlencode($name), [], [404]) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return array_filter([$this->clientSecret, $this->token]);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the Atom Registrar API, which has its own token.
 *
 * @see https://www.atom.com/api-docs
 */
final class AtomRegistrarClient extends ApiClient {

  protected const NETWORK = 'Atom registrar';

  protected const BASE_URI = 'https://www.atom.com/api/registrar/v1/';

  protected const PAGE_SIZE = 100;

  // Stops a runaway loop if the pagination ever goes wrong.
  protected const MAX_PAGES = 1000;

  public function __construct(
    ClientInterface $httpClient,
    protected string $token,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches every domain in the account.
   *
   * @return array[]
   *   Domain records as the API returns them.
   */
  public function listDomains(): array {
    $domains = [];
    for ($page = 1; $page <= self::MAX_PAGES; $page++) {
      $response = $this->request('GET', 'domains', [
        RequestOptions::QUERY => ['page' => $page, 'size' => self::PAGE_SIZE],
      ]);
      $batch = $response['data'] ?? [];
      array_push($domains, ...$batch);
      $pages = $response['pagination']['total_pages'] ?? NULL;
      if (!$batch || ($pages !== NULL ? $page >= $pages : count($batch) < self::PAGE_SIZE)) {
        break;
      }
    }
    return $domains;
  }

  /**
   * Fetches one domain.
   *
   * @return array|null
   *   The domain record, or NULL when the account doesn't hold it.
   */
  public function getDomain(string $name): ?array {
    $response = $this->request('GET', 'domains/' . rawurlencode($name), [], [404]);
    return is_array($response['data'] ?? NULL) ? $response['data'] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $this->token;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->token];
  }

}

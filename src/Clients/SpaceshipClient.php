<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Talks to the SellerHub part of the Spaceship API.
 *
 * Every write is one domain per request, and each operation allows 300
 * requests per 5 minutes, so big pushes wait out the limit as they go.
 *
 * @see https://docs.spaceship.dev/#tag/SellerHub
 */
final class SpaceshipClient extends ApiClient {

  protected const NETWORK = 'Spaceship';

  protected const BASE_URI = 'https://spaceship.dev/api/v1/sellerhub/';

  protected const PAGE_SIZE = 100;

  // The rate limit window is 300 seconds; a long push sleeps through it.
  protected const MAX_ATTEMPTS = 4;

  protected const MAX_RETRY_WAIT = 300;

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiKey,
    protected string $apiSecret,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches every SellerHub domain.
   *
   * @return array[]
   *   Domain records as the API returns them.
   */
  public function listDomains(): array {
    $domains = [];
    $skip = 0;
    do {
      $page = $this->request('GET', 'domains', [RequestOptions::QUERY => ['take' => self::PAGE_SIZE, 'skip' => $skip]]);
      $batch = $page['items'] ?? [];
      array_push($domains, ...$batch);
      $skip += count($batch);
      $total = $page['total'] ?? NULL;
    } while (count($batch) === self::PAGE_SIZE && ($total === NULL || $skip < $total));
    return $domains;
  }

  /**
   * Fetches one domain.
   *
   * @return array|null
   *   The domain record, or NULL when it is not in SellerHub.
   */
  public function getDomain(string $name): ?array {
    return $this->request('GET', 'domains/' . rawurlencode($name), [], [404]) ?: NULL;
  }

  /**
   * Lists a domain.
   *
   * @param array $domain
   *   The record to create, with a name.
   */
  public function createDomain(array $domain): void {
    $this->request('POST', 'domains', [RequestOptions::JSON => $domain]);
  }

  /**
   * Changes the given fields of a listing.
   */
  public function updateDomain(string $name, array $fields): void {
    $this->request('PATCH', 'domains/' . rawurlencode($name), [RequestOptions::JSON => $fields]);
  }

  /**
   * Removes a domain from SellerHub; one that is already gone is fine.
   */
  public function deleteDomain(string $name): void {
    $this->request('DELETE', 'domains/' . rawurlencode($name), [], [404]);
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['X-Api-Key'] = $this->apiKey;
    $options[RequestOptions::HEADERS]['X-Api-Secret'] = $this->apiSecret;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->apiSecret];
  }

  /**
   * {@inheritdoc}
   */
  protected function reference(ResponseInterface $response): ?string {
    $id = $response->getHeaderLine('spaceship-operation-id');
    return $id !== '' ? 'operation ' . $id : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function describeError(int $status, array $body): string {
    $message = parent::describeError($status, $body);
    $fields = array_map(fn(array $field) => ($field['field'] ?? '?') . ' ' . ($field['details'] ?? ''), $body['data'] ?? []);
    return $fields ? $message . ' (' . implode('; ', $fields) . ')' : $message;
  }

}

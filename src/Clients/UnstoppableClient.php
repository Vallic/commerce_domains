<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the Unstoppable Domains User API.
 *
 * Every operation is a POST to an action, with a user API key. Batch actions
 * take up to 50 items and report success per item.
 *
 * @see https://docs.unstoppabledomains.com/user-api/overview
 */
final class UnstoppableClient extends ApiClient {

  protected const NETWORK = 'Unstoppable Domains';

  protected const BASE_URI = 'https://api.unstoppabledomains.com/mcp/v1/actions/';

  protected const PAGE_SIZE = 100;

  // The most items a batch action takes.
  public const BATCH_SIZE = 50;

  // Stops a runaway loop if the pagination ever goes wrong.
  protected const MAX_PAGES = 1000;

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiKey,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches the account's DNS domains.
   *
   * @param string $status
   *   Which domains: all, for-sale or unlisted.
   *
   * @return array[]
   *   Domain records with name, expiresAt, autoRenewal and listing.
   */
  public function listDomains(string $status = 'all'): array {
    $domains = [];
    $offset = 0;
    for ($page = 0; $page < self::MAX_PAGES; $page++) {
      $response = $this->action('ud_portfolio_list', [
        'offset' => $offset,
        'limit' => self::PAGE_SIZE,
        'status' => $status,
        'registryType' => 'dns',
      ]);
      $batch = $response['domains'] ?? [];
      array_push($domains, ...$batch);
      $next = $response['pagination']['nextOffset'] ?? NULL;
      if (!$batch || empty($response['pagination']['hasMore']) || !is_int($next) || $next <= $offset) {
        break;
      }
      $offset = $next;
    }
    return $domains;
  }

  /**
   * Fetches domains in full, with their transfer flags.
   *
   * @param string[] $names
   *   Up to BATCH_SIZE domains.
   *
   * @return array[]
   *   Records for the domains found in the account.
   */
  public function getDomains(array $names): array {
    $response = $this->action('ud_domain_get', ['domains' => array_values($names)]);
    return array_values(array_filter($response['domains'] ?? [], fn($domain) => !empty($domain['found'])));
  }

  /**
   * Runs a batch action, a batch at a time.
   *
   * @param string $action
   *   The action, e.g. ud_listing_create.
   * @param string $key
   *   The body key that holds the items.
   * @param array $items
   *   The items.
   *
   * @return array[]
   *   The per-item results. Syncs log their errors, so the errors are
   *   redacted like whole-request errors.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function batch(string $action, string $key, array $items): array {
    $results = [];
    foreach (array_chunk(array_values($items), self::BATCH_SIZE) as $chunk) {
      array_push($results, ...($this->action($action, [$key => $chunk])['results'] ?? []));
    }
    return array_map(fn($result) => is_array($result) && is_string($result['error'] ?? NULL)
      ? ['error' => $this->redact($result['error'])] + $result
      : $result, $results);
  }

  /**
   * Runs an action.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function action(string $action, array $body): array {
    return $this->request('POST', $action, [RequestOptions::JSON => $body]);
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $this->apiKey;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->apiKey];
  }

}

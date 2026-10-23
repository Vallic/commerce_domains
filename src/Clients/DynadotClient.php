<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the Dynadot Advanced Domain API (api3).
 *
 * Every command is a GET with the key in the query string, so messages are
 * redacted. Failures come back as HTTP 200 with an error status inside the
 * command's response object.
 *
 * @see https://www.dynadot.com/domain/api-commands
 */
final class DynadotClient extends ApiClient {

  protected const NETWORK = 'Dynadot';

  protected const BASE_URI = 'https://api.dynadot.com/';

  protected const PAGE_SIZE = 500;

  // Stops a runaway loop if the API ever ignores the page parameters.
  protected const MAX_PAGES = 100;

  /**
   * Constructs a DynadotClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param string $apiKey
   *   The API key.
   * @param int $requestsPerMinute
   *   The account's rate limit, which depends on its API price level.
   */
  public function __construct(
    ClientInterface $httpClient,
    protected string $apiKey,
    int $requestsPerMinute,
  ) {
    parent::__construct($httpClient, max($requestsPerMinute, 1));
  }

  /**
   * Fetches every domain in the account.
   *
   * @return array[]
   *   Domain records as the API returns them, with Name and isForSale.
   */
  public function listDomains(): array {
    $domains = [];
    $seen = [];
    for ($page = 1; $page <= self::MAX_PAGES; $page++) {
      try {
        $response = $this->command('list_domain', [
          'count_per_page' => self::PAGE_SIZE,
          'page_index' => $page,
          'sort' => 'NameAsc',
        ]);
      }
      catch (ProviderException $e) {
        // A rejected key or an IP missing from the API allowlist: fixed
        // outside the code, so queued pushes should wait for it.
        throw new ProviderException($e->getMessage(), TRUE, $e);
      }
      $batch = $response['MainDomains'] ?? $response['DomainInfoList'] ?? [];
      $first = $batch[0]['Name'] ?? NULL;
      if ($first === NULL || isset($seen[$first])) {
        break;
      }
      $seen[$first] = TRUE;
      array_push($domains, ...$batch);
      if (count($batch) < self::PAGE_SIZE) {
        break;
      }
    }
    return $domains;
  }

  /**
   * Fetches a domain's marketplace listing.
   *
   * @return array|null
   *   The listing, with Price in US dollars, or NULL when Dynadot has none.
   */
  public function getListingItem(string $name): ?array {
    try {
      $response = $this->command('get_listing_item', ['domain' => $name, 'currency' => 'usd']);
    }
    catch (ProviderException $e) {
      if ($e->retryable) {
        throw $e;
      }
      return NULL;
    }
    return is_array($response['Listing'] ?? NULL) ? $response['Listing'] : NULL;
  }

  /**
   * Lists a domain in the marketplace, or changes its listing.
   *
   * @param string $name
   *   The domain.
   * @param array $params
   *   The set_for_sale parameters besides the domain and sale type.
   */
  public function setForSale(string $name, array $params): void {
    $this->command('set_for_sale', ['domains' => $name, 'for_sale_type' => 'marketplace'] + $params);
  }

  /**
   * Takes a domain off the marketplace.
   */
  public function cancelSale(string $name): void {
    $this->command('set_for_sale', ['domains' => $name, 'for_sale_type' => 'not_for_sale']);
  }

  /**
   * Runs a command and returns its response object.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function command(string $command, array $params = []): array {
    $body = $this->unwrap($this->request('GET', 'api3.json', [RequestOptions::QUERY => ['command' => $command] + $params]));
    if ($failure = $this->failure($body)) {
      throw new ProviderException($this->redact("Dynadot $command: $failure"));
    }
    return $body;
  }

  /**
   * Returns the command's response object, with its header merged in.
   *
   * Responses look like {"SetForSaleResponse": {"ResponseCode": 0, ...}};
   * some commands nest the status in a "...Header" object instead.
   */
  protected function unwrap(array $response): array {
    if (count($response) === 1 && is_array(reset($response))) {
      $response = reset($response);
    }
    foreach ($response as $key => $value) {
      if (is_string($key) && str_ends_with($key, 'Header') && is_array($value)) {
        $response += $value;
      }
    }
    return $response;
  }

  /**
   * Returns the error a response reports, else NULL.
   */
  protected function failure(array $body): ?string {
    $code = NULL;
    foreach ($body as $key => $value) {
      // One documented example spells the key "ResponseCode: ".
      if (is_string($key) && preg_match('/^(Response|Success)Code\b/', $key)) {
        $code = $value;
        break;
      }
    }
    $status = is_string($body['Status'] ?? NULL) ? strtolower($body['Status']) : NULL;
    if ($status !== 'error' && (string) $code !== '-1') {
      return NULL;
    }
    $error = $body['Error'] ?? NULL;
    return is_string($error) && $error !== '' ? $error : 'Dynadot reported an error: ' . json_encode($body);
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::QUERY] = ['key' => $this->apiKey] + ($options[RequestOptions::QUERY] ?? []);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    // Connection errors quote the whole URL, query string included.
    return [$this->apiKey, rawurlencode($this->apiKey)];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the fruits API.
 *
 * Authenticates with an OAuth2 client-credentials token. Listings are added
 * and repriced through imports, which fruits processes in the background.
 *
 * @see https://api-docs.fruits.co/
 */
final class FruitsClient extends ApiClient {

  protected const NETWORK = 'fruits';

  protected const BASE_URI = 'https://api.fruits.co/';

  protected const SCOPES = 'content.domain.read content.domain.write';

  // The API's largest page.
  protected const PAGE_SIZE = 1000;

  // The most items one import takes.
  public const IMPORT_LIMIT = 20000;

  // Import states after which nothing more happens.
  public const IMPORT_DONE = ['completed', 'failed'];

  /**
   * The access token, once fetched.
   *
   * @var string|null
   */
  protected ?string $token = NULL;

  /**
   * When the access token expires, as a Unix time.
   *
   * @var int
   */
  protected int $tokenExpires = 0;

  public function __construct(
    ClientInterface $httpClient,
    protected string $clientId,
    protected string $clientSecret,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches every domain in the portfolio.
   *
   * @return array[]
   *   Domain records as the API returns them.
   */
  public function listDomains(): array {
    $domains = [];
    $offset = 0;
    do {
      $page = $this->request('GET', 'v1/domains', [
        RequestOptions::QUERY => ['limit' => self::PAGE_SIZE, 'offset' => $offset],
      ]);
      $batch = $page['items'] ?? [];
      array_push($domains, ...$batch);
      $offset += count($batch);
      $total = $page['pagination']['total'] ?? NULL;
    } while (count($batch) === self::PAGE_SIZE && ($total === NULL || $offset < $total));
    return $domains;
  }

  /**
   * Fetches one domain.
   *
   * @return array|null
   *   The domain record, or NULL when it is not in the portfolio.
   */
  public function getDomain(string $name): ?array {
    return $this->request('GET', 'v1/domains/' . rawurlencode($name), [], [404]) ?: NULL;
  }

  /**
   * Starts an import that adds new domains and updates listed ones.
   *
   * @param array[] $items
   *   At most IMPORT_LIMIT import items, each with a name, price and currency.
   *
   * @return array
   *   The import, with its ID and state.
   */
  public function importDomains(array $items): array {
    return $this->request('POST', 'v1/imports', [
      RequestOptions::JSON => [
        'mode' => 'add_update',
        'source' => ['type' => 'items', 'items' => array_values($items)],
      ],
    ]);
  }

  /**
   * Waits for an import to finish.
   *
   * @param array $import
   *   The import as importDomains() returned it.
   * @param int $timeout
   *   How many seconds to wait at most.
   *
   * @return array
   *   The import's latest state: completed, failed, or still running when
   *   the wait timed out.
   */
  public function waitForImport(array $import, int $timeout = 60): array {
    $deadline = time() + $timeout;
    while (!in_array($import['state'] ?? NULL, self::IMPORT_DONE, TRUE) && isset($import['id']) && time() < $deadline) {
      $this->pause(2);
      $import = $this->request('GET', 'v1/imports/' . rawurlencode((string) $import['id']));
    }
    return $import;
  }

  /**
   * Removes a domain from the portfolio; one that is already gone is fine.
   */
  public function deleteDomain(string $name): void {
    $this->request('DELETE', 'v1/domains/' . rawurlencode($name), [], [404]);
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $this->token();
    return $options;
  }

  /**
   * Returns an access token, fetching a new one when it is about to expire.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function token(): string {
    if ($this->token !== NULL && time() < $this->tokenExpires - 60) {
      return $this->token;
    }
    try {
      // Fruits only accepts the credentials in the body; Basic auth gets a
      // 401 invalid_client.
      $response = $this->send('POST', 'oauth2/token', [
        RequestOptions::FORM_PARAMS => [
          'grant_type' => 'client_credentials',
          'client_id' => $this->clientId,
          'client_secret' => $this->clientSecret,
          'scope' => self::SCOPES,
        ],
      ]);
    }
    catch (ProviderException $e) {
      // Bad or revoked credentials come back as 400 or 401; either way the
      // fix is outside the code, so keep queued work for later.
      throw new ProviderException($e->getMessage(), TRUE, $e);
    }
    if (empty($response['access_token']) || !is_string($response['access_token'])) {
      throw new ProviderException('fruits returned no access token.', TRUE);
    }
    $this->token = $response['access_token'];
    $this->tokenExpires = time() + (int) ($response['expires_in'] ?? 3600);
    return $this->token;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return array_filter([$this->clientSecret, $this->token]);
  }

  /**
   * {@inheritdoc}
   */
  protected function describeError(int $status, array $body): string {
    // OAuth errors use error/error_description instead of message.
    if (isset($body['error_description']) && is_string($body['error_description'])) {
      return "HTTP $status: " . $body['error_description'];
    }
    $message = parent::describeError($status, $body);
    $fields = array_map(fn(array $field) => ($field['field'] ?? '?') . ' ' . ($field['message'] ?? ''), array_filter($body['fields'] ?? [], 'is_array'));
    return $fields ? $message . ' (' . implode('; ', $fields) . ')' : $message;
  }

}

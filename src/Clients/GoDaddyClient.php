<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the GoDaddy Domains API, version 3.
 *
 * Authenticates with a personal access token; the older key and secret don't
 * work for version 3.
 *
 * @see https://developer.godaddy.com/docs/api-users/domains/manage/list.md
 */
final class GoDaddyClient extends ApiClient {

  protected const NETWORK = 'GoDaddy';

  protected const HOST = 'api.godaddy.com';

  protected const BASE_URI = 'https://' . self::HOST . '/';

  // The API's largest page.
  protected const PAGE_SIZE = 200;

  // Stops a runaway loop if the pagination ever goes wrong.
  protected const MAX_PAGES = 1000;

  public function __construct(
    ClientInterface $httpClient,
    protected string $token,
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
    $path = 'v3/domains/domain-names?pageSize=' . self::PAGE_SIZE;
    for ($page = 0; $path !== NULL && $page < self::MAX_PAGES; $page++) {
      $response = $this->request('GET', $path);
      array_push($domains, ...($response['items'] ?? []));
      $path = self::nextPath($response['links'] ?? []);
    }
    return $domains;
  }

  /**
   * Fetches one domain.
   *
   * @return array|null
   *   The domain record, or NULL when it is not in the account.
   */
  public function getDomain(string $name): ?array {
    return $this->request('GET', 'v3/domains/domain-names/' . rawurlencode($name), [], [404]) ?: NULL;
  }

  /**
   * Returns the path of the next page, from a page's links.
   *
   * Links to another host are ignored, so the token never leaves GoDaddy.
   */
  protected static function nextPath(mixed $links): ?string {
    foreach (is_array($links) ? $links : [] as $link) {
      if (($link['rel'] ?? NULL) !== 'next' || !is_string($link['href'] ?? NULL)) {
        continue;
      }
      $url = parse_url($link['href']);
      if ($url === FALSE || empty($url['path']) || (isset($url['host']) && $url['host'] !== self::HOST)) {
        return NULL;
      }
      return ltrim($url['path'], '/') . (isset($url['query']) ? '?' . $url['query'] : '');
    }
    return NULL;
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

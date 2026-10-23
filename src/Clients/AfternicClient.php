<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\Component\Uuid\UuidInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Talks to the Afternic Aftermarket Portfolio API.
 *
 * @see https://api.afternic.com/docs/portfolio-api/
 */
final class AfternicClient extends ApiClient {

  protected const NETWORK = 'Afternic';

  protected const BASE_URI = 'https://api.afternic.com/v3/aftermarket/customers/my/';

  // The most names any $each endpoint takes in one request.
  public const BATCH_LIMIT = 500;

  protected const PAGE_SIZE = 100;

  /**
   * The X-Request-Id of the latest attempt, quoted in errors.
   */
  protected ?string $requestId = NULL;

  public function __construct(
    ClientInterface $httpClient,
    protected UuidInterface $uuid,
    protected string $apiKey,
    protected string $apiSecret,
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
      $page = $this->request('GET', 'domains', [
        RequestOptions::QUERY => ['limit' => self::PAGE_SIZE, 'offset' => $offset, 'order' => 'NAME_ASC'],
      ]);
      $batch = $page['domains'] ?? [];
      array_push($domains, ...$batch);
      $offset += count($batch);
      $total = $page['pagination']['total'] ?? NULL;
    } while (count($batch) === self::PAGE_SIZE && ($total === NULL || $offset < $total));
    return $domains;
  }

  /**
   * Fetches the given domains; names that are not listed are left out.
   *
   * @param string[] $names
   *   At most BATCH_LIMIT names.
   *
   * @return array[]
   *   Domain records as the API returns them.
   */
  public function getDomains(array $names): array {
    $response = $this->request('POST', 'domains/$each/get', [RequestOptions::JSON => ['names' => array_values($names)]]);
    return $response['domains'] ?? [];
  }

  /**
   * Creates or updates domains.
   *
   * @param array[] $domains
   *   At most BATCH_LIMIT domain records, each with a name.
   *
   * @return array<string, string>
   *   Errors keyed by domain name; names that succeeded are missing.
   */
  public function upsertDomains(array $domains): array {
    $response = $this->request('POST', 'domains/$each/upsert', [RequestOptions::JSON => ['domains' => array_values($domains)]]);
    return $this->itemErrors(array_column($domains, 'name'), $response);
  }

  /**
   * Removes domains from the portfolio.
   *
   * @param string[] $names
   *   At most BATCH_LIMIT names.
   *
   * @return array<string, string>
   *   Errors keyed by domain name; names that succeeded are missing.
   */
  public function deleteDomains(array $names): array {
    $response = $this->request('POST', 'domains/$each/delete', [RequestOptions::JSON => ['names' => array_values($names)]]);
    // A name that is already gone is what we wanted.
    return $this->itemErrors($names, $response, [404]);
  }

  /**
   * Collects the failures out of a per-domain batch response.
   *
   * @param string[] $names
   *   The names that were sent.
   * @param array $response
   *   Results keyed by domain name, each with an HTTP code and maybe an error.
   * @param int[] $ok
   *   Error codes that count as success for this operation.
   */
  protected function itemErrors(array $names, array $response, array $ok = []): array {
    $errors = [];
    foreach ($names as $name) {
      $result = $response[$name] ?? NULL;
      if ($result === NULL) {
        $errors[$name] = 'Afternic returned no result for this domain.';
      }
      elseif (($result['code'] ?? 0) >= 300 && !in_array($result['code'], $ok, TRUE)) {
        $errors[$name] = $this->describeError((int) $result['code'], $result['error'] ?? []);
      }
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['Authorization'] = 'sso-key ' . $this->apiKey . ':' . $this->apiSecret;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function attemptHeaders(): array {
    // A fresh ID per attempt, so Afternic support can find each one.
    $this->requestId = $this->uuid->generate();
    return ['X-Request-Id' => $this->requestId] + parent::attemptHeaders();
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
    return $this->requestId ? 'request ' . $this->requestId : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function describeError(int $status, array $body): string {
    $message = trim(($body['code'] ?? 'HTTP ' . $status) . ': ' . ($body['message'] ?? 'Request failed'));
    $fields = array_map(fn(array $field) => ($field['path'] ?? '?') . ' ' . ($field['message'] ?? ''), $body['fields'] ?? []);
    if ($fields) {
      $message .= ' (' . implode('; ', $fields) . ')';
    }
    $details = $body['details'] ?? $body['detail'] ?? NULL;
    if (is_string($details) && $details !== '') {
      $message .= ' ' . $details;
    }
    return $message;
  }

}

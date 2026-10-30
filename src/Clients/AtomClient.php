<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the Atom Seller API.
 *
 * Every call is a GET with the token in the query string, so messages are
 * redacted. The spec documents the fields of a domain but not the envelope
 * around lists or errors, so both are read defensively.
 *
 * @see https://www.atom.com/api-docs
 */
final class AtomClient extends ApiClient {

  protected const NETWORK = 'Atom';

  protected const BASE_URI = 'https://www.atom.com/api/marketplace/';

  protected const PAGE_SIZE = 100;

  // Stops a runaway loop if the API ever ignores the page parameter.
  protected const MAX_PAGES = 200;

  // The listing types the list endpoint filters by.
  public const TYPES = ['standard', 'premium', 'geo'];

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiToken,
    protected string $userId,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches every listing of one type.
   *
   * @param string $type
   *   One of TYPES.
   *
   * @return array[]
   *   Domain records as the API returns them.
   */
  public function listDomains(string $type): array {
    $domains = [];
    $seen = [];
    for ($page = 1; $page <= self::MAX_PAGES; $page++) {
      $response = $this->request('GET', 'domains', [
        RequestOptions::QUERY => ['type' => $type, 'page' => $page, 'limit' => self::PAGE_SIZE],
      ]);
      $batch = $this->records($response);
      if ($batch === NULL) {
        if ($failure = $this->failure($response)) {
          // Failures can quote the response, which may echo the request.
          throw new ProviderException($this->redact("Atom GET domains: $failure"), TRUE);
        }
        break;
      }
      $first = $batch[0]['domain_name'] ?? NULL;
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
   * Lists a domain as a standard listing, which goes live straight away.
   */
  public function addDomain(string $name, int $price): void {
    $this->call('add-domain', ['domain_type' => 'standard', 'domain_name' => $name, 'price' => $price]);
  }

  /**
   * Changes a listing's selling price.
   */
  public function updateSellingPrice(string $name, int $price): void {
    $this->call('update-selling-price', ['domain_name' => $name, 'selling_price' => $price]);
  }

  /**
   * Removes a standard listing; premium ones need a notice to Atom.
   */
  public function removeDomain(string $name): void {
    $this->call('remove-domain', ['domain_name' => $name]);
  }

  /**
   * Appraises a domain, in or out of the account.
   *
   * Counts toward the account's daily appraisals; past them, Atom answers
   * HTTP 429.
   *
   * @return array
   *   The appraisal, with atom_appraisal in dollars, domain_score,
   *   tld_taken_count, positive_signals and negative_signals.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function appraise(string $name): array {
    $response = $this->request('GET', 'domain-appraisal', [RequestOptions::QUERY => ['domain_name' => $name]]);
    if (!array_key_exists('atom_appraisal', $response)) {
      $failure = $this->failure($response) ?? (is_string($response['message'] ?? NULL) ? $response['message'] : 'Atom returned no appraisal.');
      throw new ProviderException($this->redact("Atom GET domain-appraisal: $failure"));
    }
    return $response;
  }

  /**
   * Fetches a listing's views, shortlists and offers, all time.
   *
   * @return array
   *   Counts as total_views, total_shortlists and total_offers.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function domainAnalytics(string $name): array {
    $response = $this->request('GET', 'domain-analytics', [RequestOptions::QUERY => ['domain_name' => $name]]);
    if (!array_key_exists('total_views', $response)) {
      $failure = $this->failure($response) ?? (is_string($response['message'] ?? NULL) ? $response['message'] : 'Atom returned no analytics.');
      throw new ProviderException($this->redact("Atom GET domain-analytics: $failure"));
    }
    return $response;
  }

  /**
   * Sends a write and checks the body for a failure reported with HTTP 200.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function call(string $path, array $query): array {
    $response = $this->request('GET', $path, [RequestOptions::QUERY => $query]);
    if ($failure = $this->failure($response)) {
      throw new ProviderException($this->redact("Atom GET $path: $failure"));
    }
    return $response;
  }

  /**
   * Finds the list of domain records in a response.
   *
   * @return array[]|null
   *   The records, or NULL when the response holds no list.
   */
  protected function records(array $response): ?array {
    if ($response && array_is_list($response)) {
      return $response;
    }
    foreach (['data', 'domains', 'items', 'results'] as $key) {
      $value = $response[$key] ?? NULL;
      if (is_array($value)) {
        return array_is_list($value) ? $value : $this->records($value);
      }
    }
    return NULL;
  }

  /**
   * Returns the error in a response that reports one, else NULL.
   */
  protected function failure(array $response): ?string {
    $status = $response['status'] ?? NULL;
    $error = $response['error'] ?? NULL;
    $failed = ($response['success'] ?? NULL) === FALSE
      || $status === FALSE
      || (is_string($status) && in_array(strtolower($status), ['error', 'fail', 'failed', 'failure'], TRUE))
      || ($error !== NULL && $error !== FALSE && $error !== '' && $error !== []);
    if (!$failed) {
      return NULL;
    }
    $message = $response['message'] ?? (is_string($error) ? $error : NULL);
    return is_string($message) && $message !== '' ? $message : 'Atom reported a failure: ' . json_encode($response);
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::QUERY] = ['api_token' => $this->apiToken, 'user_id' => $this->userId] + ($options[RequestOptions::QUERY] ?? []);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    // Connection errors quote the whole URL, query string included.
    return [$this->apiToken, rawurlencode($this->apiToken)];
  }

}

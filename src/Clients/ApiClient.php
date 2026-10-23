<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Base for the JSON APIs marketplace plugins talk to.
 *
 * Retries rate limits, outages and dropped connections, and turns every other
 * failure into a ProviderException that never contains a credential.
 */
abstract class ApiClient {

  // The network's name, for messages.
  protected const NETWORK = '';

  // Prepended to every request path.
  protected const BASE_URI = '';

  protected const MAX_ATTEMPTS = 3;

  // Longest Retry-After we sleep for before giving up on a request.
  protected const MAX_RETRY_WAIT = 30;

  protected const USER_AGENT = 'CommerceDomains/1.0 (+https://www.drupal.org/project/commerce_domains)';

  // Shortest gap between the starts of two requests, in seconds, for APIs.
  /**
   * That take one request at a time.
   */
  protected float $minInterval = 0.0;

  /**
   * When the latest request started.
   */
  protected float $lastRequest = 0.0;

  public function __construct(protected ClientInterface $httpClient) {}

  /**
   * Adds the credentials to a request.
   */
  protected function authorize(array $options): array {
    return $options;
  }

  /**
   * Returns the headers for one attempt of a request.
   */
  protected function attemptHeaders(): array {
    return [
      'User-Agent' => static::USER_AGENT,
      'Accept' => 'application/json',
    ];
  }

  /**
   * Returns values that must never appear in a message, like tokens in URLs.
   *
   * @return string[]
   *   The secrets.
   */
  protected function secrets(): array {
    return [];
  }

  /**
   * Returns how the network identifies a request, for support tickets.
   */
  protected function reference(ResponseInterface $response): ?string {
    return NULL;
  }

  /**
   * Formats an error response as one line.
   */
  protected function describeError(int $status, array $body): string {
    $message = $body['message'] ?? $body['detail'] ?? $body['error'] ?? NULL;
    return is_string($message) && $message !== '' ? "HTTP $status: $message" : "HTTP $status";
  }

  /**
   * Sends an authorized request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path below BASE_URI, or a full URL.
   * @param array $options
   *   Guzzle request options.
   * @param int[] $ok
   *   Error statuses that count as success, e.g. 404 when deleting. The
   *   result is then empty.
   *
   * @return array
   *   The decoded JSON body; empty when there is none.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function request(string $method, string $path, array $options = [], array $ok = []): array {
    return $this->send($method, $path, $this->authorize($options), $ok);
  }

  /**
   * Sends a request, retrying rate limits and server errors.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function send(string $method, string $path, array $options = [], array $ok = []): array {
    $options += [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::CONNECT_TIMEOUT => 10,
      RequestOptions::TIMEOUT => 60,
    ];
    $url = str_contains($path, '://') ? $path : static::BASE_URI . $path;

    for ($attempt = 1;; $attempt++) {
      $options[RequestOptions::HEADERS] = $this->attemptHeaders() + ($options[RequestOptions::HEADERS] ?? []);
      $this->throttle();
      try {
        $response = $this->httpClient->request($method, $url, $options);
      }
      catch (GuzzleException $e) {
        if ($attempt < static::MAX_ATTEMPTS) {
          $this->pause(2 ** $attempt);
          continue;
        }
        throw new ProviderException($this->redact(sprintf('%s %s %s failed: %s', static::NETWORK, $method, $path, $e->getMessage())), TRUE, $e);
      }

      $status = $response->getStatusCode();
      if (($status === 429 || $status >= 500) && $attempt < static::MAX_ATTEMPTS) {
        $this->pause($this->retryWait($response, $attempt));
        continue;
      }

      $body = json_decode((string) $response->getBody(), TRUE);
      $body = is_array($body) ? $body : [];
      if (in_array($status, $ok, TRUE)) {
        return [];
      }
      if ($status >= 300) {
        $retryable = in_array($status, [401, 403, 429], TRUE) || $status >= 500;
        $reference = $this->reference($response);
        $message = sprintf('%s %s %s: %s', static::NETWORK, $method, $path, $this->describeError($status, $body)) . ($reference ? " [$reference]" : '');
        throw new ProviderException($this->redact($message), $retryable);
      }
      return $body;
    }
  }

  /**
   * Runs an operation for each domain, collecting per-domain failures.
   *
   * A failure that would hit every domain (rate limit, outage, bad
   * credentials) stops the run instead.
   *
   * @param string[] $names
   *   The domain names.
   * @param callable(string): void $operation
   *   Called with each name.
   *
   * @return array<string, string>
   *   Errors keyed by domain name; names that succeeded are missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function eachDomain(array $names, callable $operation): array {
    $errors = [];
    foreach ($names as $name) {
      try {
        $operation($name);
      }
      catch (ProviderException $e) {
        if ($e->retryable) {
          throw $e;
        }
        $errors[$name] = $e->getMessage();
      }
    }
    return $errors;
  }

  /**
   * Returns how many seconds to wait before retrying.
   */
  protected function retryWait(ResponseInterface $response, int $attempt): int {
    $retry_after = $response->getHeaderLine('Retry-After');
    $wait = ctype_digit($retry_after) ? (int) $retry_after : 2 ** $attempt;
    return min(max($wait, 1), static::MAX_RETRY_WAIT);
  }

  /**
   * Waits before a retry.
   */
  protected function pause(int $seconds): void {
    sleep($seconds);
  }

  /**
   * Waits until the next request is allowed under $minInterval.
   */
  protected function throttle(): void {
    if ($this->minInterval > 0) {
      $wait = $this->lastRequest + $this->minInterval - microtime(TRUE);
      if ($wait > 0) {
        usleep((int) ceil($wait * 1_000_000));
      }
    }
    $this->lastRequest = microtime(TRUE);
  }

  /**
   * Removes credentials from a message.
   */
  protected function redact(string $message): string {
    $secrets = array_filter($this->secrets(), fn(string $secret) => $secret !== '');
    return $secrets ? str_replace($secrets, '***', $message) : $message;
  }

}

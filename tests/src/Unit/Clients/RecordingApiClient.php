<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\ApiClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * An API client for testing ApiClient: records waits instead of sleeping.
 */
class RecordingApiClient extends ApiClient {

  protected const NETWORK = 'Test';

  protected const BASE_URI = 'https://api.example.test/';

  /**
   * The waits retries asked for, in seconds.
   *
   * @var int[]
   */
  public array $waits = [];

  public function __construct(ClientInterface $httpClient, protected string $secret = 'Sec-ret-123', float $minInterval = 0.0) {
    parent::__construct($httpClient);
    $this->minInterval = $minInterval;
  }

  /**
   * Sends an authorized GET.
   */
  public function get(string $path, array $ok = []): array {
    return $this->request('GET', $path, [], $ok);
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['X-Secret'] = $this->secret;
    $options[RequestOptions::QUERY]['token'] = $this->secret;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->secret];
  }

  /**
   * {@inheritdoc}
   */
  protected function pause(int $seconds): void {
    $this->waits[] = $seconds;
  }

}

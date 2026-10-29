<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_credentials_test;

use Drupal\commerce_domains\Clients\ApiClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * An API client sending its credentials as headers, as most real ones do.
 */
final class TestApiClient extends ApiClient {

  protected const NETWORK = 'Test';

  protected const BASE_URI = 'https://api.example.test/';

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiKey,
    protected string $apiSecret,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Calls the API.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function ping(): array {
    return $this->request('GET', 'ping');
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
    return [$this->apiKey, $this->apiSecret];
  }

}

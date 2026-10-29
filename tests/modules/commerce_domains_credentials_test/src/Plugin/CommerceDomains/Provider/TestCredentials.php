<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_credentials_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains_credentials_test\TestApiClient;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A provider whose API is whatever HTTP client the test puts in the container.
 *
 * Its calls read the credentials the way the real plugins do: from the
 * settings, after assertReady(), into an API client kept for later calls.
 */
#[CommerceDomainsProvider(
  id: 'test_credentials',
  label: new TranslatableMarkup('Test credentials'),
)]
final class TestCredentials extends ProviderPluginBase {

  protected const CREDENTIALS = [
    'api_key' => 'COMMERCE_DOMAINS_TEST_API_KEY',
    'api_secret' => 'COMMERCE_DOMAINS_TEST_API_SECRET',
  ];

  protected const OPTIONAL_CREDENTIALS = [
    'extra_token' => 'COMMERCE_DOMAINS_TEST_EXTRA_TOKEN',
  ];

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains_credentials_test\TestApiClient|null
   */
  protected ?TestApiClient $client = NULL;

  /**
   * Calls the API with the required credentials.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function ping(): void {
    $this->client()->ping();
  }

  /**
   * Calls the part of the API that also needs the optional token.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function pingExtra(): void {
    $this->assertReady();
    $this->assertCredential('extra_token');
    $this->httpClient->request('GET', 'https://api.example.test/extra', [
      'headers' => ['Authorization' => 'Bearer ' . $this->configuration['extra_token']],
    ]);
  }

  /**
   * Whether the API client has been built.
   */
  public function hasClient(): bool {
    return $this->client !== NULL;
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): TestApiClient {
    $this->assertReady();
    return $this->client ??= new TestApiClient($this->httpClient, (string) $this->configuration['api_key'], (string) $this->configuration['api_secret']);
  }

}

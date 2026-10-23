<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from a Porkbun account.
 */
#[CommerceDomainsProvider(
  id: 'porkbun',
  label: new TranslatableMarkup('Porkbun'),
  description: new TranslatableMarkup('Reads registrations through the Porkbun API.'),
)]
final class Porkbun extends ProviderPluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_key' => 'PORKBUN_API_KEY',
    'secret_key' => 'PORKBUN_SECRET_KEY',
  ];

  // 20 requests every 2 seconds.
  protected const REQUESTS_PER_MINUTE = 600;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\PorkbunClient|null
   */
  protected ?PorkbunClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    return self::fewNames($names) ? array_filter(array_map($client->getDomain(...), array_values($names))) : $client->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (!isset($record['domain'])) {
      return NULL;
    }
    // Flags are 1 or 0; older replies sent "1".
    $flag = fn(string $key) => isset($record[$key]) ? (bool) (int) $record[$key] : NULL;
    return new Registration(
      name: DomainName::normalize((string) $record['domain']),
      expires: self::timestamp($record['expireDate'] ?? NULL),
      locked: $flag('securityLock'),
      autoRenew: $flag('autoRenew'),
      status: isset($record['status']) ? strtolower((string) $record['status']) : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create the API key and secret at porkbun.com/account/api; reading registrations needs only the read scope.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): PorkbunClient {
    $this->assertReady();
    return $this->client ??= new PorkbunClient($this->httpClient, (string) $this->configuration['api_key'], (string) $this->configuration['secret_key'], $this->requestsPerMinute());
  }

}

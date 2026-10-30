<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\GoDaddyClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from a GoDaddy account.
 */
#[CommerceDomainsProvider(
  id: 'godaddy',
  label: new TranslatableMarkup('GoDaddy'),
  description: new TranslatableMarkup('Reads registrations through the GoDaddy Domains API.'),
)]
final class GoDaddy extends ProviderPluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_token' => 'GODADDY_API_TOKEN',
  ];

  protected const REQUESTS_PER_MINUTE = 60;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\GoDaddyClient|null
   */
  protected ?GoDaddyClient $client = NULL;

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
    return new Registration(
      name: DomainName::normalize((string) $record['domain']),
      expires: self::timestamp($record['expiresAt'] ?? NULL),
      locked: isset($record['transferLock']) ? (bool) $record['transferLock'] : NULL,
      autoRenew: isset($record['autoRenew']) ? (bool) $record['autoRenew'] : NULL,
      status: isset($record['status']) ? strtolower((string) $record['status']) : NULL,
      registered: self::timestamp($record['createdAt'] ?? NULL),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('Create a personal access token at developer.godaddy.com/personal-access-token with the domains.domain:read permission. Tokens expire after a year at most.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): GoDaddyClient {
    $this->assertReady();
    return $this->client ??= new GoDaddyClient($this->httpClient, (string) $this->configuration['api_token'], $this->requestsPerMinute());
  }

}

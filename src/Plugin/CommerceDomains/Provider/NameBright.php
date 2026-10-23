<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\NameBrightClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from a NameBright account.
 */
#[CommerceDomainsProvider(
  id: 'namebright',
  label: new TranslatableMarkup('NameBright'),
  description: new TranslatableMarkup('Reads registrations through the NameBright API.'),
)]
final class NameBright extends ProviderPluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'client_id' => 'NAMEBRIGHT_CLIENT_ID',
    'client_secret' => 'NAMEBRIGHT_CLIENT_SECRET',
  ];

  // 30 requests every 30 seconds.
  protected const REQUESTS_PER_MINUTE = 60;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\NameBrightClient|null
   */
  protected ?NameBrightClient $client = NULL;

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
    if (!isset($record['domainName'])) {
      return NULL;
    }
    return new Registration(
      name: DomainName::normalize((string) $record['domainName']),
      expires: self::timestamp($record['expirationDate'] ?? NULL),
      locked: isset($record['locked']) ? (bool) $record['locked'] : NULL,
      autoRenew: isset($record['autoRenew']) ? (bool) $record['autoRenew'] : NULL,
      status: isset($record['status']) ? (string) $record['status'] : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t("Create an API key under My Account → API Management once NameBright approves API access, and allow the server's IP address on it.");
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): NameBrightClient {
    $this->assertReady();
    return $this->client ??= new NameBrightClient($this->httpClient, (string) $this->configuration['client_id'], (string) $this->configuration['client_secret'], $this->requestsPerMinute());
  }

}

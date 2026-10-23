<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\NamecheapClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from a Namecheap account.
 */
#[CommerceDomainsProvider(
  id: 'namecheap',
  label: new TranslatableMarkup('Namecheap'),
  description: new TranslatableMarkup('Reads registrations through the Namecheap API.'),
)]
final class Namecheap extends ProviderPluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_user' => 'NAMECHEAP_API_USER',
    'api_key' => 'NAMECHEAP_API_KEY',
    'client_ip' => 'NAMECHEAP_CLIENT_IP',
  ];

  // Also 700 an hour, which a portfolio read of 100 domains a page never
  // gets near.
  protected const REQUESTS_PER_MINUTE = 50;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\NamecheapClient|null
   */
  protected ?NamecheapClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    // A page holds 100 domains, so the list is cheap even for a few names.
    return $this->client()->listDomains();
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    if (empty($record['Name'])) {
      return NULL;
    }
    $flag = fn(string $key) => isset($record[$key]) ? strtolower($record[$key]) === 'true' : NULL;
    return new Registration(
      name: DomainName::normalize($record['Name']),
      // Dates are MM/DD/YYYY.
      expires: self::timestamp($record['Expires'] ?? NULL),
      // Namecheap documents IsLocked as "domain changes are not allowed".
      locked: $flag('IsLocked'),
      autoRenew: $flag('AutoRenew'),
      status: $flag('IsExpired') ? 'expired' : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t("Turn on API access under Profile → Tools → Namecheap API Access, and allow the server's IPv4 address there; the client IP is that address. Namecheap only grants API access to accounts with 20 domains, a $50 balance, or $50 spent in two years.");
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): NamecheapClient {
    $this->assertReady();
    return $this->client ??= new NamecheapClient(
      $this->httpClient,
      (string) $this->configuration['api_user'],
      (string) $this->configuration['api_key'],
      (string) $this->configuration['client_ip'],
      $this->requestsPerMinute(),
    );
  }

}

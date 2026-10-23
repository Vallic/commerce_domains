<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\IonosClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Registration;

/**
 * Reads registrations from an IONOS account.
 *
 * The account list carries the expiry of active domains; the transfer lock
 * and auto-renew flag need a request per domain, which the hourly limit only
 * allows for a few names and for domains the list has no expiry for.
 */
#[CommerceDomainsProvider(
  id: 'ionos',
  label: new TranslatableMarkup('IONOS'),
  description: new TranslatableMarkup('Reads registrations through the IONOS Domains API.'),
)]
final class Ionos extends ProviderPluginBase implements RegistrarInterface {

  use RegistrarTrait;

  protected const CREDENTIALS = [
    'api_key' => 'IONOS_API_KEY',
  ];

  // Keys allow 1,200 requests an hour.
  protected const REQUESTS_PER_MINUTE = 20;

  /**
   * The API client, once created.
   *
   * @var \Drupal\commerce_domains\Clients\IonosClient|null
   */
  protected ?IonosClient $client = NULL;

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    $client = $this->client();
    foreach ($client->listDomains() as $domain) {
      $name = DomainName::normalize((string) ($domain['encodedName'] ?? $domain['name'] ?? ''));
      if ($name === '' || ($names !== NULL && !in_array($name, $names, TRUE))) {
        continue;
      }
      $listed_expiry = $domain['status']['provisioningStatus']['setToExpireOn'] ?? NULL;
      if (isset($domain['id']) && (self::fewNames($names) || ($listed_expiry === NULL && empty($domain['pendingProvisioning'])))) {
        $domain = $client->getDomain((string) $domain['id']) ?? $domain;
      }
      yield $domain;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function registration(array $record): ?Registration {
    $name = $record['encodedName'] ?? $record['name'] ?? NULL;
    if (!is_string($name) || $name === '') {
      return NULL;
    }
    $provisioning = $record['status']['provisioningStatus'] ?? [];
    $type = $provisioning['type'] ?? NULL;
    if (isset($record['autoRenew'])) {
      // A cancellation date stands in for the flag when billing and expiry
      // dates differ.
      $auto_renew = $record['autoRenew'] && empty($record['cancellationDate']);
    }
    else {
      // Active domains have a renewal date when they renew; expiring ones
      // are cancelled at expiry.
      $auto_renew = match ($type) {
        'ACTIVE' => isset($provisioning['setToRenewOn']),
        'EXPIRING' => FALSE,
        default => NULL,
      };
    }
    $lock = array_filter([$record['transferLock'] ?? NULL, $record['domainLock'] ?? NULL], 'is_bool');
    return new Registration(
      name: DomainName::normalize($name),
      expires: self::timestamp($record['expirationDate'] ?? $provisioning['setToExpireOn'] ?? NULL),
      locked: $lock ? in_array(TRUE, $lock, TRUE) : NULL,
      autoRenew: $auto_renew,
      status: is_string($type) ? strtolower($type) : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function credentialsHelp(): string {
    return (string) $this->t('The key is the public prefix and the secret joined by a dot, as created at developer.hosting.ionos.com/keys after signing up for API access.');
  }

  /**
   * Returns the API client.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function client(): IonosClient {
    $this->assertReady();
    return $this->client ??= new IonosClient($this->httpClient, (string) $this->configuration['api_key'], $this->requestsPerMinute());
  }

}

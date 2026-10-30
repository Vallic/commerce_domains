<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Clients\NamecheapClient;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads registrations from a Namecheap account, and manages them.
 *
 * The API has no commands for auto-renew or the auth code; those stay in the
 * Namecheap account.
 */
#[CommerceDomainsProvider(
  id: 'namecheap',
  label: new TranslatableMarkup('Namecheap'),
  description: new TranslatableMarkup('Reads and manages registrations through the Namecheap API.'),
)]
final class Namecheap extends ProviderPluginBase implements DomainManagementInterface {

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
   * Splits names into the SLD and TLD the DNS commands take.
   *
   * @var \Drupal\commerce_domains\DomainNameParser
   */
  protected DomainNameParser $parser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->parser = $container->get(DomainNameParser::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function registrationRecords(?array $names): iterable {
    // A page holds 100 domains, so the list is cheap even for a few names.
    $client = $this->client();
    $records = $client->listDomains();
    if (!self::fewNames($names)) {
      return $records;
    }
    // The list has no transfer lock, so ask for it per domain.
    $wanted = array_flip($names);
    $records = array_filter($records, fn(array $record) => isset($wanted[DomainName::normalize($record['Name'] ?? '')]));
    foreach ($records as &$record) {
      $record['RegistrarLockStatus'] = $client->getRegistrarLock($record['Name']) ? 'true' : 'false';
    }
    return $records;
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
      // Only for a few names: the list's IsLocked is not the transfer lock.
      locked: $flag('RegistrarLockStatus'),
      autoRenew: $flag('AutoRenew'),
      status: $flag('IsExpired') ? 'expired' : NULL,
      registered: self::timestamp($record['Created'] ?? NULL),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getNameservers(string $name): array {
    $domain = $this->parse($name);
    return array_map('strtolower', $this->client()->getNameservers($domain->label, $domain->extension));
  }

  /**
   * {@inheritdoc}
   */
  public function setNameservers(string $name, array $nameservers): void {
    $domain = $this->parse($name);
    $this->client()->setNameservers($domain->label, $domain->extension, $nameservers);
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    $this->client()->setRegistrarLock($name, $locked);
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    throw new ProviderException('The Namecheap API cannot change auto-renew; change it in the Namecheap account.');
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    throw new ProviderException('The Namecheap API cannot give auth codes; request one in the Namecheap account, which emails it.');
  }

  /**
   * Splits a name into label and extension.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function parse(string $name): DomainName {
    return $this->parser->parse($name) ?? throw new ProviderException("$name is not a registrable domain.");
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

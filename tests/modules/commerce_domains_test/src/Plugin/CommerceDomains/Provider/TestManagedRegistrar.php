<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A registrar that manages domains, its account kept in State.
 *
 * State key commerce_domains_test.managed: per domain, expires, nameservers,
 * locked and auto_renew. A domain named refuse.com refuses every change.
 */
#[CommerceDomainsProvider(
  id: 'test_managed_registrar',
  label: new TranslatableMarkup('Test managed registrar'),
)]
final class TestManagedRegistrar extends ProviderPluginBase implements DomainManagementInterface {

  protected const KEY = 'commerce_domains_test.managed';

  /**
   * The state store holding the account.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchRegistrations(?array $names = NULL): array {
    $registrations = [];
    foreach ($this->state->get(self::KEY, []) as $name => $domain) {
      $registrations[$name] = new Registration($name, $domain['expires'] ?? NULL, $domain['locked'] ?? NULL, $domain['auto_renew'] ?? NULL, 'active');
    }
    return $names === NULL ? $registrations : array_intersect_key($registrations, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  public function getNameservers(string $name): array {
    return $this->state->get(self::KEY, [])[$name]['nameservers'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setNameservers(string $name, array $nameservers): void {
    $this->change($name, 'nameservers', $nameservers);
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    $this->change($name, 'locked', $locked);
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    $this->change($name, 'auto_renew', $auto_renew);
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthCode(string $name): string {
    // Domains marked so stand in for registrars that email the code.
    $account = $this->state->get(self::KEY, []);
    return empty($account[$name]['emails_code']) ? 'code-' . $name : '';
  }

  /**
   * Changes one value of a domain.
   */
  protected function change(string $name, string $key, mixed $value): void {
    if ($name === 'refuse.com') {
      throw new ProviderException('Refused.');
    }
    $account = $this->state->get(self::KEY, []);
    $account[$name][$key] = $value;
    $this->state->set(self::KEY, $account);
  }

}

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
 * State keys, both under commerce_domains_test.:
 * - managed: per domain, expires, nameservers, locked and auto_renew, and
 *   emails_code for a registrar that emails the auth code instead.
 * - managed_calls: each management call, as the method, the domain and the
 *   arguments. Never what a call returned, so an auth code is never kept.
 *
 * A domain named refuse.com refuses every change, and its auth code.
 */
#[CommerceDomainsProvider(
  id: 'test_managed_registrar',
  label: new TranslatableMarkup('Test managed registrar'),
)]
final class TestManagedRegistrar extends ProviderPluginBase implements DomainManagementInterface {

  protected const KEY = 'commerce_domains_test.managed';

  public const CALLS = 'commerce_domains_test.managed_calls';

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
    $this->record('getNameservers', $name);
    return $this->state->get(self::KEY, [])[$name]['nameservers'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setNameservers(string $name, array $nameservers): void {
    $this->record('setNameservers', $name, $nameservers);
    $this->change($name, 'nameservers', $nameservers);
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferLock(string $name, bool $locked): void {
    $this->record('setTransferLock', $name, $locked);
    $this->change($name, 'locked', $locked);
  }

  /**
   * {@inheritdoc}
   */
  public function setAutoRenew(string $name, bool $auto_renew): void {
    $this->record('setAutoRenew', $name, $auto_renew);
    $this->change($name, 'auto_renew', $auto_renew);
  }

  /**
   * {@inheritdoc}
   *
   * The code is made from the name rather than kept, so tests can look for it
   * anywhere it must not be.
   */
  public function getAuthCode(string $name): string {
    $this->record('getAuthCode', $name);
    if ($name === 'refuse.com') {
      throw new ProviderException('Refused.');
    }
    // Domains marked so stand in for registrars that email the code.
    $account = $this->state->get(self::KEY, []);
    return empty($account[$name]['emails_code']) ? 'code-' . $name : '';
  }

  /**
   * Returns the management calls made so far.
   *
   * @return array<int, array<int, mixed>>
   *   Each call as the method, the domain and the arguments.
   */
  public static function calls(StateInterface $state): array {
    return $state->get(self::CALLS, []);
  }

  /**
   * Records a call, without what it returns.
   */
  protected function record(string $method, string $name, mixed ...$arguments): void {
    $calls = $this->state->get(self::CALLS, []);
    $calls[] = [$method, $name, ...$arguments];
    $this->state->set(self::CALLS, $calls);
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

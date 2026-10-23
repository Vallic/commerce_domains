<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A registrar whose account is kept in State.
 *
 * State keys, all under commerce_domains_test.:
 * - registrations: expiry Unix times keyed by domain.
 * - registrar_fail: 'retryable' or 'permanent' to make every call throw.
 * - registrar_calls: how many times the account was read.
 */
#[CommerceDomainsProvider(
  id: 'test_registrar',
  label: new TranslatableMarkup('Test registrar'),
)]
final class TestRegistrar extends ProviderPluginBase implements RegistrarInterface {

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
    $this->state->set('commerce_domains_test.registrar_calls', $this->state->get('commerce_domains_test.registrar_calls', 0) + 1);
    $fail = $this->state->get('commerce_domains_test.registrar_fail');
    if ($fail) {
      throw new ProviderException("Test registrar failed ($fail).", $fail === 'retryable');
    }
    $registrations = [];
    foreach ($this->state->get('commerce_domains_test.registrations', []) as $name => $expires) {
      $registrations[$name] = new Registration($name, $expires, TRUE, TRUE, 'active');
    }
    return $names === NULL ? $registrations : array_intersect_key($registrations, array_flip($names));
  }

}

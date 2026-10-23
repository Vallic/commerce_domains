<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\ProviderException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ConfigurableTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Render\Markup;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for provider plugins.
 *
 * Subclasses keep the plugin constructor's signature, so create() can build
 * them with new static().
 *
 * @phpstan-consistent-constructor
 */
abstract class ProviderPluginBase extends PluginBase implements ProviderPluginInterface, ContainerFactoryPluginInterface {

  use ConfigurableTrait;

  // Settings that hold API credentials, mapped to the environment variable a
  // site would read them from in settings.php. Keys never go through the
  // form, so they can't end up in exported config.
  protected const CREDENTIALS = [];

  /**
   * The HTTP client the API clients use.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return array_fill_keys(array_keys(static::CREDENTIALS), '');
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupError(): ?string {
    foreach (array_keys(static::CREDENTIALS) as $key) {
      if (empty($this->configuration[$key])) {
        return sprintf('%s API credentials are missing: set %s.', $this->label(), implode(' and ', static::CREDENTIALS));
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    if (static::CREDENTIALS) {
      // The variable names are constants, so they are safe as markup.
      $variables = Markup::create(implode(' and ', array_map(fn(string $variable) => "<code>$variable</code>", static::CREDENTIALS)));
      $form['credentials'] = [
        '#type' => 'item',
        '#title' => $this->t('API credentials'),
        '#markup' => $this->formatPlural(count(static::CREDENTIALS), 'Set in settings.php, e.g. from the @variables environment variable.', 'Set in settings.php, e.g. from the @variables environment variables.', ['@variables' => $variables]) . ' ' . $this->credentialsHelp(),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Returns where to create the API credentials, for the settings form.
   */
  protected function credentialsHelp(): string {
    return '';
  }

  /**
   * Throws when the plugin cannot talk to the API yet.
   *
   * Missing keys are fixed outside the code, so the error is retryable and
   * queued work waits for them.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function assertReady(): void {
    if ($error = $this->getSetupError()) {
      throw new ProviderException($error, TRUE);
    }
  }

}

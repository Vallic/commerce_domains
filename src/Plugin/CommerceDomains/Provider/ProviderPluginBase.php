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

  // Credentials only some capabilities need, mapped the same way. A missing
  // one only fails the calls that use it.
  protected const OPTIONAL_CREDENTIALS = [];

  // The API's documented rate limit in requests per minute, or 0 for none.
  // Plugins with one get a setting for it, so sites can go lower.
  protected const REQUESTS_PER_MINUTE = 0;

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
    $defaults = array_fill_keys(array_keys(static::CREDENTIALS + static::OPTIONAL_CREDENTIALS), '');
    if (static::REQUESTS_PER_MINUTE) {
      $defaults['requests_per_minute'] = static::REQUESTS_PER_MINUTE;
    }
    return $defaults;
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
        return sprintf('%s API credentials are missing: set %s.', $this->label(), self::andList(static::CREDENTIALS));
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    if (static::CREDENTIALS || static::OPTIONAL_CREDENTIALS) {
      // The variable names are constants, so they are safe as markup.
      $code = fn(array $variables) => Markup::create(self::andList(array_map(fn(string $variable) => "<code>$variable</code>", $variables)));
      $markup = [];
      if (static::CREDENTIALS) {
        $markup[] = $this->formatPlural(count(static::CREDENTIALS), 'Set in settings.php, e.g. from the @variables environment variable.', 'Set in settings.php, e.g. from the @variables environment variables.', ['@variables' => $code(static::CREDENTIALS)]);
      }
      if (static::OPTIONAL_CREDENTIALS) {
        $markup[] = $this->t('Optional: @variables.', ['@variables' => $code(static::OPTIONAL_CREDENTIALS)]);
      }
      $markup[] = $this->credentialsHelp();
      $form['credentials'] = [
        '#type' => 'item',
        '#title' => $this->t('API credentials'),
        '#markup' => implode(' ', array_map('strval', array_filter($markup))),
      ];
    }
    if (static::REQUESTS_PER_MINUTE) {
      $form['requests_per_minute'] = [
        '#type' => 'number',
        '#title' => $this->t('Requests per minute'),
        '#description' => $this->t('The API allows @count; lower it when other tools share the key.', ['@count' => static::REQUESTS_PER_MINUTE]),
        '#min' => 1,
        '#default_value' => $this->configuration['requests_per_minute'],
        '#required' => TRUE,
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
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    if (static::REQUESTS_PER_MINUTE) {
      $this->configuration['requests_per_minute'] = (int) $form_state->getValue('requests_per_minute');
    }
  }

  /**
   * Returns where to create the API credentials, for the settings form.
   */
  protected function credentialsHelp(): string {
    return '';
  }

  /**
   * Joins names as "a, b and c".
   */
  protected static function andList(array $names): string {
    $last = array_pop($names);
    return $names ? implode(', ', $names) . ' and ' . $last : (string) $last;
  }

  /**
   * Returns the requests per minute the API client may send.
   */
  protected function requestsPerMinute(): int {
    return max((int) ($this->configuration['requests_per_minute'] ?? static::REQUESTS_PER_MINUTE), 0);
  }

  /**
   * Reads a date an API returned.
   *
   * @param mixed $value
   *   The date.
   * @param string $timezone
   *   The time zone of dates that don't name one.
   *
   * @return int|null
   *   The Unix time, or NULL when the value is empty or not a date.
   */
  protected static function timestamp(mixed $value, string $timezone = 'UTC'): ?int {
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable(trim($value), new \DateTimeZone($timezone)))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Throws when an optional credential a call needs is missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function assertCredential(string $key): void {
    if (empty($this->configuration[$key])) {
      $variable = static::OPTIONAL_CREDENTIALS[$key] ?? static::CREDENTIALS[$key] ?? $key;
      throw new ProviderException(sprintf('%s needs another credential for this: set %s.', $this->label(), $variable), TRUE);
    }
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

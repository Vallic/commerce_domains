<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Clients\ApiClient;
use Drupal\commerce_domains\ProviderException;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ConfigurableTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Render\Markup;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for provider plugins.
 *
 * Subclasses keep the plugin constructor's signature, so create() can build
 * them with new static().
 *
 * Plugins read credentials from $this->configuration after assertReady():
 * that fills the ones settings.php leaves empty from the provider's keys.
 *
 * @phpstan-consistent-constructor
 */
abstract class ProviderPluginBase extends PluginBase implements ProviderPluginInterface, ContainerFactoryPluginInterface {

  use ConfigurableTrait;

  // Settings that hold API credentials, mapped to the environment variable a
  // site would read them from in settings.php. Credentials never go through
  // the form, so they can't end up in exported config: settings.php sets
  // them, or they come from keys (Key module) chosen on the provider.
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

  /**
   * The key repository, when the Key module is installed.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected ?KeyRepositoryInterface $keyRepository = NULL;

  /**
   * The keys to read credentials from, keyed by setting.
   *
   * @var array<string, string>
   */
  protected array $credentialKeys = [];

  /**
   * Where each credential comes from, once worked out.
   *
   * @var array<string, array>|null
   */
  protected ?array $credentialSources = NULL;

  /**
   * What the settings now holding a key's value held before, by setting.
   *
   * @var array<string, mixed>
   */
  protected array $keyedSettings = [];

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
    // Keys are optional: without the Key module, credentials only come from
    // settings.php, as before.
    if ($container->has('key.repository')) {
      $instance->keyRepository = $container->get('key.repository');
    }
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
   *
   * Values read from keys are left out, so saving the provider never writes
   * one to config.
   */
  public function getConfiguration(): array {
    return array_replace($this->configuration, $this->keyedSettings);
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): static {
    $this->configuration = NestedArray::mergeDeepArray([$this->defaultConfiguration(), $configuration], TRUE);
    // Keys are read again against the new settings.
    $this->keyedSettings = [];
    $this->credentialSources = NULL;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setCredentialKeys(array $keys): static {
    $keys = array_filter(array_map('strval', array_intersect_key($keys, static::CREDENTIALS + static::OPTIONAL_CREDENTIALS)), fn(string $id) => $id !== '');
    ksort($keys);
    if ($keys !== $this->credentialKeys) {
      $this->configuration = $this->getConfiguration();
      $this->keyedSettings = [];
      $this->credentialSources = NULL;
      $this->credentialKeys = $keys;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentialSources(): array {
    if ($this->credentialSources !== NULL) {
      return $this->credentialSources;
    }
    $this->configuration = $this->getConfiguration();
    $this->keyedSettings = [];
    $sources = [];
    foreach (static::CREDENTIALS + static::OPTIONAL_CREDENTIALS as $setting => $variable) {
      $source = [
        'variable' => $variable,
        'required' => isset(static::CREDENTIALS[$setting]),
        'source' => NULL,
        'key' => $this->credentialKeys[$setting] ?? NULL,
        'key_label' => NULL,
        'problem' => NULL,
      ];
      if (!empty($this->configuration[$setting])) {
        // A value from settings.php wins over a key.
        $source['source'] = 'settings';
      }
      elseif ($source['key'] !== NULL) {
        $source = $this->readKey($setting, $source);
      }
      $sources[$setting] = $source;
    }
    return $this->credentialSources = $sources;
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
    $missing = array_filter($this->getCredentialSources(), fn(array $source) => $source['required'] && $source['source'] === NULL);
    return $missing ? sprintf('%s API credentials are missing: %s.', $this->label(), $this->describeMissing($missing)) : NULL;
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
   * Whether a key keeps its value in config, as Key's Configuration provider.
   *
   * Config is exported, so such a key would put the secret in every export.
   */
  public static function keyStoresValueInConfig(KeyInterface $key): bool {
    if ($key->get('key_provider') === 'config') {
      return TRUE;
    }
    $provider = $key->getKeyProvider();
    $definition = $provider instanceof PluginInspectionInterface ? $provider->getPluginDefinition() : [];
    if (!is_array($definition)) {
      return FALSE;
    }
    // Key 1.18 tags providers by storage; older releases name a method.
    return in_array('config', (array) ($definition['tags'] ?? []), TRUE) || ($definition['storage_method'] ?? NULL) === 'config';
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    // A serialized plugin, e.g. in a cached form, never carries a key's value:
    // keys are read again when needed. Nor its API clients, which hold the
    // credentials and are built again from them.
    $this->configuration = $this->getConfiguration();
    $this->keyedSettings = [];
    $this->credentialSources = NULL;
    $vars = get_object_vars($this);
    return array_values(array_filter(parent::__sleep(), fn(string $name) => !(($vars[$name] ?? NULL) instanceof ApiClient)));
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
   * Fills a setting from its key, or notes why the key can't fill it.
   *
   * @param string $setting
   *   The setting.
   * @param array $source
   *   The setting's entry in getCredentialSources(), with a key.
   *
   * @return array
   *   The entry, with the outcome.
   */
  protected function readKey(string $setting, array $source): array {
    if (!$this->keyRepository) {
      $source['problem'] = 'key_module';
      return $source;
    }
    $key = $this->keyRepository->getKey($source['key']);
    if (!$key instanceof KeyInterface) {
      $source['problem'] = 'key_missing';
      return $source;
    }
    $source['key_label'] = (string) $key->label();
    try {
      // The value of a key kept in config is never read: refusing it keeps
      // sites from relying on a secret their config exports carry.
      if (static::keyStoresValueInConfig($key)) {
        $source['problem'] = 'key_config';
        return $source;
      }
      // Providers give NULL for an unset variable or a missing file.
      $value = (string) $key->getKeyValue();
    }
    catch (\Exception) {
      // Remote key providers can fail; their messages are not passed on, in
      // case they quote what they fetched.
      $source['problem'] = 'key_error';
      return $source;
    }
    if (empty($value)) {
      $source['problem'] = 'key_empty';
      return $source;
    }
    $this->keyedSettings[$setting] = $this->configuration[$setting] ?? '';
    $this->configuration[$setting] = $value;
    $source['source'] = 'key';
    return $source;
  }

  /**
   * Says what to do about missing credentials, never quoting a value.
   *
   * @param array<string, array> $sources
   *   The missing credentials' entries in getCredentialSources().
   */
  protected function describeMissing(array $sources): string {
    $unset = array_column(array_filter($sources, fn(array $source) => $source['key'] === NULL), 'variable');
    $parts = $unset ? ['set ' . self::andList($unset)] : [];
    foreach ($sources as $source) {
      if ($source['key'] === NULL) {
        continue;
      }
      $key = sprintf('key "%s" for %s', $source['key_label'] ?? $source['key'], $source['variable']);
      $parts[] = match ($source['problem']) {
        'key_module' => "$key needs the Key module",
        'key_missing' => "$key does not exist",
        'key_config' => "$key keeps its value in config, which is exported with the site: use a key that reads an environment variable or a file",
        'key_error' => "$key could not be read",
        default => "$key is empty",
      };
    }
    return implode('; ', $parts);
  }

  /**
   * Throws when an optional credential a call needs is missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function assertCredential(string $setting): void {
    $source = $this->getCredentialSources()[$setting] ?? NULL;
    if ($source === NULL ? empty($this->configuration[$setting]) : $source['source'] === NULL) {
      $missing = $source === NULL ? "set $setting" : $this->describeMissing([$setting => $source]);
      throw new ProviderException(sprintf('%s needs another credential for this: %s.', $this->label(), $missing), TRUE);
    }
  }

  /**
   * Throws when the plugin cannot talk to the API yet.
   *
   * Missing keys are fixed outside the code, so the error is retryable and
   * queued work waits for them. Afterwards, the credentials are in
   * $this->configuration, keys' values included.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function assertReady(): void {
    if ($error = $this->getSetupError()) {
      throw new ProviderException($error, TRUE);
    }
  }

}

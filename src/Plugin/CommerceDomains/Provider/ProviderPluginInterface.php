<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * The API of a company domains are registered or listed with.
 *
 * What a plugin can do comes from the capability interfaces it also
 * implements, such as MarketplaceInterface.
 *
 * API credentials come from the plugin's settings, which settings.php fills
 * with config overrides, or else from keys of the Key module. A value from
 * settings.php wins over a key.
 */
interface ProviderPluginInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Returns the company's name.
   */
  public function label(): string;

  /**
   * Returns why the plugin cannot talk to the API, e.g. missing keys.
   *
   * The message says where a missing credential was looked for, never a
   * credential's value.
   *
   * @return string|null
   *   The problem, or NULL when the plugin is ready.
   */
  public function getSetupError(): ?string;

  /**
   * Sets the keys (Key module) to read credentials from.
   *
   * @param array<string, string> $keys
   *   Key IDs, keyed by the setting each fills. Settings that aren't
   *   credentials are ignored.
   */
  public function setCredentialKeys(array $keys): static;

  /**
   * Returns where each credential comes from, never its value.
   *
   * @return array<string, array{variable: string, required: bool, source: 'settings'|'key'|null, key: string|null, key_label: string|null, problem: string|null}>
   *   Keyed by setting:
   *   - variable: the environment variable settings.php would read it from.
   *   - required: FALSE for credentials only some calls need.
   *   - source: 'settings' for a value in the plugin's settings, as
   *     settings.php sets them; 'key' for the chosen key's value; NULL when
   *     there is none.
   *   - key, key_label: the chosen key's ID and name, if any.
   *   - problem: why the chosen key gives no value: 'key_module' (the Key
   *     module is not installed), 'key_missing' (no such key), 'key_config'
   *     (refused: the key keeps its value in config, which is exported),
   *     'key_empty' or 'key_error' (reading it failed).
   */
  public function getCredentialSources(): array;

}

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
 */
interface ProviderPluginInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Returns the company's name.
   */
  public function label(): string;

  /**
   * Returns why the plugin cannot talk to the API, e.g. missing keys.
   *
   * @return string|null
   *   The problem, or NULL when the plugin is ready.
   */
  public function getSetupError(): ?string;

}

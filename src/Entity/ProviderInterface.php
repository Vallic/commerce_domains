<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Entity;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;

/**
 * A company domains are registered or listed with.
 *
 * A provider with a plugin can be reached through its API; one without is
 * just a name, e.g. a registrar we never call. Domain products reference
 * providers as their registrar.
 */
interface ProviderInterface extends ConfigEntityInterface, EntityWithPluginCollectionInterface {

  /**
   * Returns the configured plugin, or NULL for a provider without an API.
   */
  public function getPlugin(): ?ProviderPluginInterface;

  /**
   * Returns the plugin ID, or NULL for a provider without an API.
   */
  public function getPluginId(): ?string;

  /**
   * Sets the plugin ID, discarding the previous plugin's settings.
   */
  public function setPluginId(?string $plugin_id): static;

  /**
   * Whether the provider's plugin implements a capability.
   *
   * @param class-string $capability
   *   A capability interface, e.g. MarketplaceInterface::class.
   */
  public function hasCapability(string $capability): bool;

  /**
   * Whether cron refreshes registration details from the registrar daily.
   */
  public function syncsRegistrations(): bool;

  /**
   * Sets whether cron refreshes registration details daily.
   */
  public function setRegistrationSync(bool $sync): static;

}

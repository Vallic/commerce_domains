<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Manages provider plugins.
 */
class ProviderPluginManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/CommerceDomains/Provider', $namespaces, $module_handler, ProviderPluginInterface::class, CommerceDomainsProvider::class);
    $this->alterInfo('commerce_domains_provider_info');
    $this->setCacheBackend($cache_backend, 'commerce_domains_provider_plugins');
  }

  /**
   * Returns the IDs of the plugins that implement a capability.
   *
   * @param class-string $capability
   *   A capability interface, e.g. MarketplaceInterface::class.
   *
   * @return string[]
   *   The plugin IDs.
   */
  public function getPluginIdsWith(string $capability): array {
    return array_keys(array_filter($this->getDefinitions(), fn(array $definition) => is_subclass_of($definition['class'], $capability)));
  }

}

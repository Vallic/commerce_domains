<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters by provider: listings by marketplace, or domains by registrar.
 *
 * The definition's "capability" limits the options to providers whose plugin
 * implements that interface; with "plugin optional", providers without a
 * plugin are options too.
 */
#[ViewsFilter('commerce_domains_listing_provider')]
final class ListingProvider extends InOperator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): array {
    $this->valueOptions = [];
    $capability = $this->definition['capability'] ?? NULL;
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      $optional = !empty($this->definition['plugin optional']) && $provider->getPluginId() === NULL;
      if ($capability === NULL || $optional || $provider->hasCapability($capability)) {
        $this->valueOptions[(string) $provider->id()] = (string) $provider->label();
      }
    }
    asort($this->valueOptions);
    return $this->valueOptions;
  }

}

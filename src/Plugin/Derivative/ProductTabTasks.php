<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Derivative;

use Drupal\commerce_domains\ProductTabs;
use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a tab beside Edit on domains for each domain tab.
 */
final class ProductTabTasks extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    protected ProductTabs $productTabs,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get(ProductTabs::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];
    foreach ($this->productTabs->tabs() as $operation => $tab) {
      $this->derivatives[$operation] = [
        'title' => $tab['title'],
        'route_name' => 'commerce_domains.product_tab.' . $operation,
        'base_route' => 'entity.commerce_product.canonical',
        'weight' => $tab['weight'],
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Routing;

use Drupal\commerce_domains\ProductTabs;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Adds a route for each domain tab: /product/{commerce_product}/{path}.
 */
final class ProductTabRoutes implements ContainerInjectionInterface {

  public function __construct(
    protected ProductTabs $productTabs,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ProductTabs::class));
  }

  /**
   * Returns the routes.
   *
   * @return \Symfony\Component\Routing\Route[]
   *   The routes, keyed by name.
   */
  public function routes(): array {
    $routes = [];
    foreach ($this->productTabs->tabs() as $operation => $tab) {
      // A tab with a page of its own.
      if (isset($tab['route'])) {
        continue;
      }
      $routes['commerce_domains.product_tab.' . $operation] = new Route(
        '/product/{commerce_product}/' . $tab['path'],
        [
          '_entity_form' => 'commerce_product.' . $operation,
          '_title_callback' => '\Drupal\commerce_domains\Form\DomainTabForm::title',
        ],
        [
          '_custom_access' => '\Drupal\commerce_domains\Form\DomainTabForm::access',
          'commerce_product' => '\d+',
        ],
        [
          '_admin_route' => TRUE,
          'parameters' => ['commerce_product' => ['type' => 'entity:commerce_product']],
        ],
      );
    }
    return $routes;
  }

}

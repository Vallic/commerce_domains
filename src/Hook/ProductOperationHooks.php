<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Orders a domain's tabs, and offers them as operations in lists.
 *
 * Apart from building entity types (see ProductTabHooks), as these services
 * read routes and entity types themselves.
 */
class ProductOperationHooks {

  // The domain's tabs after View and Edit, in order; the weight each gets.
  public const ORDER = [
    'commerce_domains.product_tab:domain_costs' => 11,
    'commerce_domains.product_tab:domain_sale' => 12,
    'commerce_domains.product_tab:domain_valuation' => 13,
    'commerce_domains.product_registrar' => 14,
    'commerce_domains.product_dns' => 15,
    // Commerce Pricelist's prices of the product.
    'views_view:view.commerce_pricelist_product_prices.page' => 16,
    'commerce_domains_marketplace.product_marketplaces' => 17,
  ];

  public function __construct(
    protected LocalTaskManagerInterface $localTaskManager,
    protected RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Implements hook_local_tasks_alter().
   *
   * After Views, which adds its tabs in this hook.
   */
  #[Hook('local_tasks_alter', order: Order::Last)]
  public function localTasksAlter(array &$local_tasks): void {
    foreach (self::ORDER as $id => $weight) {
      if (isset($local_tasks[$id]) && ($local_tasks[$id]['base_route'] ?? NULL) === 'entity.commerce_product.canonical') {
        $local_tasks[$id]['weight'] = $weight;
      }
    }
  }

  /**
   * Implements hook_entity_operation().
   *
   * The domain's tabs, beside Edit in the product and domain lists.
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity, CacheableMetadata $cacheability): array {
    if (!$entity instanceof ProductInterface || !$entity->hasField('domain')) {
      return [];
    }
    $definitions = $this->localTaskManager->getDefinitions();
    $operations = [];
    foreach (self::ORDER as $id => $weight) {
      $definition = $definitions[$id] ?? NULL;
      if (!$definition || ($definition['base_route'] ?? NULL) !== 'entity.commerce_product.canonical') {
        continue;
      }
      try {
        $route = $this->routeProvider->getRouteByName($definition['route_name']);
      }
      catch (RouteNotFoundException) {
        continue;
      }
      // Tabs of other pages of the product only.
      if (!str_contains($route->getPath(), '{commerce_product}')) {
        continue;
      }
      $url = Url::fromRoute($definition['route_name'], ['commerce_product' => $entity->id()]);
      $access = $url->access(NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $operations[str_replace([':', '.'], '_', $id)] = [
          'title' => $definition['title'],
          'url' => $url,
          'weight' => 10 + $weight,
        ];
      }
    }
    return $operations;
  }

}

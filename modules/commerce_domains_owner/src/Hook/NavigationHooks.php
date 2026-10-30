<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Puts the owner pages beside the domain overview, when the site has it.
 *
 * The overview and its Marketplace listings tab are views of Commerce
 * Domains Marketplace; without them, the dashboard is a Commerce menu item
 * with its own tabs.
 */
class NavigationHooks {

  // The domain overview's menu link and route.
  protected const OVERVIEW_LINK = 'views_view:views.commerce_domains_overview.page_1';

  protected const OVERVIEW_ROUTE = 'view.commerce_domains_overview.page_1';

  // The owner pages' tabs.
  protected const TASKS = [
    'commerce_domains_owner.dashboard',
    'commerce_domains_owner.expiring',
    'commerce_domains_owner.profit',
  ];

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * Moves the dashboard to Commerce > Domains > Dashboard, first there, and
   * adds the expiring domains and the profit beside it.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    if (isset($links[self::OVERVIEW_LINK], $links['commerce_domains_owner.dashboard'])) {
      $links['commerce_domains_owner.dashboard']['parent'] = self::OVERVIEW_LINK;
      $links['commerce_domains_owner.dashboard']['title'] = 'Dashboard';
      $links['commerce_domains_owner.dashboard']['weight'] = -50;
      $pages = [
        'commerce_domains_owner.expiring' => ['Expiring soon', -20],
        'commerce_domains_owner.profit' => ['Sales and profit', -15],
      ];
      foreach ($pages as $id => [$title, $weight]) {
        $links[$id] = [
          'id' => $id,
          'title' => new TranslatableMarkup($title),
          'route_name' => $id,
          'parent' => self::OVERVIEW_LINK,
          'menu_name' => $links[self::OVERVIEW_LINK]['menu_name'] ?? 'admin',
          'weight' => $weight,
          'provider' => 'commerce_domains_owner',
        ];
      }
    }
  }

  /**
   * Implements hook_local_tasks_alter().
   *
   * Adds the owner pages to the overview's tabs, under the same condition
   * as its Overview tab. After Views, which sets the base route of its tabs
   * in this hook.
   */
  #[Hook('local_tasks_alter', order: Order::Last)]
  public function localTasksAlter(array &$local_tasks): void {
    $listings = $local_tasks['views_view:view.commerce_domains_listings.page_1'] ?? NULL;
    if (!$listings || ($listings['base_route'] ?? NULL) !== self::OVERVIEW_ROUTE) {
      return;
    }
    foreach (self::TASKS as $id) {
      if (isset($local_tasks[$id])) {
        $local_tasks[$id]['base_route'] = self::OVERVIEW_ROUTE;
      }
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Hook;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Keeps the listing records tidy, and describes them to Views.
 */
class ListingHooks {

  use StringTranslationTrait;

  public function __construct(
    protected ListingRecorder $recorder,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_delete() for commerce_domains_provider.
   */
  #[Hook('commerce_domains_provider_delete')]
  public function providerDelete(ProviderInterface $provider): void {
    $this->recorder->deleteProvider((string) $provider->id());
    $this->state->delete(SyncScheduler::CHECKED_KEY . $provider->id());
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for commerce_product.
   */
  #[Hook('commerce_product_delete')]
  public function productDelete(ProductInterface $product): void {
    $this->recorder->unlinkProduct((int) $product->id());
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * Gathers the domain pages under Commerce > Domains, the overview's menu
   * link, when the site has the overview: its listings, the sync, offers,
   * handovers and the domain settings. The other modules move their own.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    $parent = 'views_view:views.commerce_domains_overview.page_1';
    if (!isset($links[$parent])) {
      return;
    }
    $add = [
      'commerce_domains_marketplace.listings' => [
        new TranslatableMarkup('Marketplace listings'),
        'view.commerce_domains_listings.page_1',
        -40,
      ],
      'commerce_domains_marketplace.sync' => [
        new TranslatableMarkup('Sync marketplaces'),
        'commerce_domains_marketplace.sync',
        -35,
      ],
      'commerce_domains_marketplace.settings' => [
        new TranslatableMarkup('Settings'),
        'commerce_domains.configuration',
        50,
      ],
    ];
    foreach ($add as $id => [$title, $route, $weight]) {
      $links[$id] = [
        'id' => $id,
        'title' => $title,
        'route_name' => $route,
        'parent' => $parent,
        'menu_name' => $links[$parent]['menu_name'] ?? 'admin',
        'weight' => $weight,
        'provider' => 'commerce_domains_marketplace',
      ];
    }
    // The listings view is optional config too.
    if ($this->configFactory->get('views.view.commerce_domains_listings')->isNew()) {
      unset($links['commerce_domains_marketplace.listings']);
    }
    $move = [
      'entity.commerce_domains_offer.collection' => [new TranslatableMarkup('Offers'), -30],
      'entity.commerce_domains_handover.collection' => [new TranslatableMarkup('Handovers'), -25],
    ];
    foreach ($move as $id => [$title, $weight]) {
      if (isset($links[$id])) {
        $links[$id]['parent'] = $parent;
        $links[$id]['title'] = $title;
        $links[$id]['weight'] = $weight;
      }
    }
  }

  /**
   * Implements hook_local_tasks_alter().
   *
   * Gives the domain overview an Overview tab beside the listings tab, which
   * the listings view adds. Both views are optional config. After Views, as
   * Views adds its tabs in this hook.
   */
  #[Hook('local_tasks_alter', order: Order::Last)]
  public function localTasksAlter(array &$local_tasks): void {
    $listings = $local_tasks['views_view:view.commerce_domains_listings.page_1'] ?? NULL;
    if ($listings && ($listings['base_route'] ?? NULL) === 'view.commerce_domains_overview.page_1') {
      $local_tasks['commerce_domains_marketplace.overview'] = [
        'id' => 'commerce_domains_marketplace.overview',
        'title' => $this->t('Overview'),
        'route_name' => 'view.commerce_domains_overview.page_1',
        'base_route' => 'view.commerce_domains_overview.page_1',
        'weight' => -10,
      ] + $listings;
    }
  }

  /**
   * Implements hook_views_data().
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $table = ListingRecorder::TABLE;
    $data[$table]['table'] = [
      'group' => $this->t('Domain listing'),
      'provider' => 'commerce_domains_marketplace',
      'base' => [
        'field' => 'domain',
        'title' => $this->t('Domain listings'),
        'help' => $this->t("Each catalog domain's listing on each marketplace, as the last sync found it."),
      ],
    ];
    $data[$table]['domain'] = [
      'title' => $this->t('Domain'),
      'field' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'string'],
    ];
    $data[$table]['provider'] = [
      'title' => $this->t('Marketplace'),
      'field' => ['id' => 'commerce_domains_listing_provider'],
      'filter' => [
        'id' => 'commerce_domains_listing_provider',
        'capability' => MarketplaceInterface::class,
      ],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'string'],
    ];
    $data[$table]['product_id'] = [
      'title' => $this->t('Product'),
      'help' => $this->t("The domain's product."),
      'field' => ['id' => 'numeric'],
      'filter' => ['id' => 'numeric'],
      'relationship' => [
        'id' => 'standard',
        'base' => 'commerce_product_field_data',
        'base field' => 'product_id',
        'label' => $this->t('Product'),
      ],
    ];
    $data[$table]['status'] = [
      'title' => $this->t('Status'),
      'help' => $this->t('Sold ones say when the sale was found.'),
      'field' => [
        'id' => 'commerce_domains_listing_status',
        'additional fields' => ['sold'],
      ],
      'filter' => ['id' => 'commerce_domains_listing_status'],
      'sort' => ['id' => 'standard'],
    ];
    $data[$table]['note'] = [
      'title' => $this->t('Note'),
      'help' => $this->t('What is pending, why it is left alone, or the error.'),
      'field' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ];
    $data[$table]['listing'] = [
      'title' => $this->t('Listing'),
      'help' => $this->t("The listing's settings, as the marketplace's plugin describes them."),
      'field' => [
        'id' => 'commerce_domains_listing_summary',
        'additional fields' => ['provider'],
      ],
    ];
    $dates = [
      'checked' => $this->t('Checked'),
      'pushed' => $this->t('Pushed'),
      'changed' => $this->t('Changed'),
      'sold' => $this->t('Sold'),
    ];
    foreach ($dates as $field => $title) {
      $data[$table][$field] = [
        'title' => $title,
        'field' => ['id' => 'date'],
        'filter' => ['id' => 'date'],
        'sort' => ['id' => 'date'],
      ];
    }
    $data[$table]['sold']['help'] = $this->t('When a sync found the marketplace selling the domain.');
    $counts = [
      'views' => $this->t('Views'),
      'leads' => $this->t('Leads'),
      'offers' => $this->t('Offers'),
      'saves' => $this->t('Saves'),
    ];
    foreach ($counts as $field => $title) {
      $data[$table][$field] = [
        'title' => $title,
        'help' => $this->t('As the marketplace counts them: Afternic over the last 30 days, Atom all time. Read daily.'),
        'field' => ['id' => 'numeric'],
        'filter' => ['id' => 'numeric'],
        'sort' => ['id' => 'standard'],
      ];
    }
    $data[$table]['stats_days'] = [
      'title' => $this->t('Days the counts cover'),
      'help' => $this->t('Empty for all time.'),
      'field' => ['id' => 'numeric'],
    ];
    $data[$table]['stats_checked'] = [
      'title' => $this->t('Counts read'),
      'help' => $this->t('When the views, leads and offers were last read.'),
      'field' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
    ];
    return $data;
  }

  /**
   * Implements hook_views_data_alter().
   *
   * Adds the marketplace listings and the registrar check to products, and
   * lets products be filtered by registrar from a list.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (!isset($data['commerce_product_field_data'])) {
      return;
    }
    // Registrars by name, rather than by typing their ID.
    if (isset($data['commerce_product__domain_registrar']['domain_registrar_target_id'])) {
      $data['commerce_product__domain_registrar']['domain_registrar_provider'] = [
        'title' => $this->t('Registrar (from a list)'),
        'help' => $this->t("The domain's registrar, chosen by name."),
        'group' => $this->t('Product'),
        'filter' => [
          'id' => 'commerce_domains_listing_provider',
          'field' => 'domain_registrar_target_id',
          // A registrar recorded by name has no plugin.
          'capability' => RegistrarInterface::class,
          'plugin optional' => TRUE,
        ],
      ];
    }
    $data['commerce_product_field_data']['commerce_domains_listings'] = [
      'title' => $this->t('Marketplace listings'),
      'help' => $this->t("The domain's status on each marketplace, as the last sync found it."),
      'field' => ['id' => 'commerce_domains_product_listings'],
    ];
    $data['commerce_product_field_data']['commerce_domains_registrar_check'] = [
      'title' => $this->t('Registrar check'),
      'help' => $this->t("When the registrar's account was last read whole, and whether it held the domain."),
      'field' => ['id' => 'commerce_domains_registrar_check'],
    ];
    $data['commerce_product_field_data']['commerce_domains_listing'] = [
      'title' => $this->t('Domain listings'),
      'help' => $this->t("The domain's listings, one row per marketplace."),
      'relationship' => [
        'id' => 'standard',
        'base' => ListingRecorder::TABLE,
        'base field' => 'product_id',
        'field' => 'product_id',
        'label' => $this->t('Domain listings'),
      ],
    ];
  }

}

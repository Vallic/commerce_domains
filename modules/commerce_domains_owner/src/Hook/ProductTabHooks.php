<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adds the Costs, Sale and Valuation tabs beside a domain's Edit tab.
 *
 * Building entity types asks for the tabs, so the class needs no services:
 * one that led back to entity types (the exchanger reads them) would loop.
 */
class ProductTabHooks {

  /**
   * Implements hook_commerce_domains_product_tabs().
   */
  #[Hook('commerce_domains_product_tabs')]
  public function productTabs(): array {
    return [
      'domain_costs' => [
        'title' => new TranslatableMarkup('Costs'),
        'path' => 'costs',
        'fields' => ProductHooks::TABS['domain_costs'],
        'weight' => 6,
      ],
      'domain_sale' => [
        'title' => new TranslatableMarkup('Sale'),
        'path' => 'sale',
        'fields' => ProductHooks::TABS['domain_sale'],
        'weight' => 7,
      ],
      'domain_valuation' => [
        'title' => new TranslatableMarkup('Valuation'),
        'path' => 'valuation',
        'fields' => ProductHooks::TABS['domain_valuation'],
        'weight' => 8,
        'read_only' => TRUE,
      ],
    ];
  }

}

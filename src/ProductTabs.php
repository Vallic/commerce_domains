<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * The tabs that edit parts of a domain beside its Edit tab.
 *
 * Modules declare tabs in hook_commerce_domains_product_tabs(): each edits
 * a few fields of the domain on a page of its own, and the Edit form leaves
 * those fields out. Commerce Domains declares Details; Commerce Domains
 * Owner declares Costs, Sale and Valuation.
 */
class ProductTabs {

  /**
   * The tabs, once collected.
   *
   * @var array<string, array>|null
   */
  protected ?array $tabs = NULL;

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns the tabs, keyed by form operation.
   *
   * @return array<string, array{title: \Stringable|string, path?: string, route?: string, fields: string[], weight: int, read_only: bool}>
   *   Each tab's title, its path below /product/{id}/ (or the route of a
   *   page of its own that shows the tab's form), the fields it edits, its
   *   weight among the product's tabs, and whether it only shows them.
   */
  public function tabs(): array {
    // Kept once found: early in a rebuild the hooks may not answer yet.
    if (!$this->tabs) {
      $this->tabs = self::collect($this->moduleHandler);
    }
    return $this->tabs;
  }

  /**
   * Asks the modules for their tabs.
   *
   * @return array<string, array>
   *   The tabs, keyed by form operation, lightest first.
   */
  public static function collect(ModuleHandlerInterface $module_handler): array {
    $tabs = [];
    foreach ($module_handler->invokeAll('commerce_domains_product_tabs') as $operation => $tab) {
      $tabs[$operation] = $tab + ['weight' => 0, 'read_only' => FALSE, 'fields' => []];
    }
    uasort($tabs, fn(array $a, array $b) => $a['weight'] <=> $b['weight']);
    return $tabs;
  }

  /**
   * Returns a tab, or NULL when there is none for the operation.
   */
  public function tab(string $operation): ?array {
    return $this->tabs()[$operation] ?? NULL;
  }

  /**
   * Returns the fields some tab edits, which the Edit form leaves out.
   *
   * @return string[]
   *   Field names.
   */
  public function fields(): array {
    return array_values(array_unique(array_merge(...array_column($this->tabs(), 'fields'))));
  }

}

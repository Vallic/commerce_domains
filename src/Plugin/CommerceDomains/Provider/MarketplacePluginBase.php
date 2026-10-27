<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\CatalogItem;

/**
 * Base class for providers with a marketplace API.
 *
 * Adds the comparison a sync uses to the shared pricing rules.
 */
abstract class MarketplacePluginBase extends ListingPluginBase implements MarketplaceInterface {

  /**
   * {@inheritdoc}
   */
  public function manages(CatalogItem $item): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function changedFields(array $wanted, array $current): array {
    $changed = [];
    foreach ($wanted as $field => $value) {
      if (!array_key_exists($field, $current) || $current[$field] !== $value) {
        $changed[] = $field;
      }
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    return is_scalar($value) || $value === NULL ? var_export($value, TRUE) : json_encode($value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    return NULL;
  }

  /**
   * Reads a numeric string as an int when it is whole.
   */
  protected static function number(string|int|float $value): int|float {
    $value = (float) $value;
    return floor($value) == $value ? (int) $value : $value;
  }

  /**
   * Formats a whole amount of money for people reviewing a sync.
   */
  protected function formatMoney(int|float $amount, string $symbol = '$'): string {
    return $symbol . number_format($amount, is_float($amount) && floor($amount) != $amount ? 2 : 0);
  }

}

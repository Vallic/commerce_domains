<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\filter;

use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;

/**
 * Filters listings by status.
 */
#[ViewsFilter('commerce_domains_listing_status')]
final class ListingStatus extends InOperator {

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): array {
    $this->valueOptions = array_map('strval', ListingRecorder::statusLabels());
    return $this->valueOptions;
  }

}

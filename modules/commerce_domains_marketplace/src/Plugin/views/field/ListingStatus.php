<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\field;

use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\Component\Render\MarkupInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Shows a listing's status by its label.
 */
#[ViewsField('commerce_domains_listing_status')]
final class ListingStatus extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface {
    $status = (string) $this->getValue($values);
    return ListingRecorder::statusLabels()[$status] ?? $this->sanitizeValue($status);
  }

}

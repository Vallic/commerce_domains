<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\field;

use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a listing's status by its label, and when a sale was found.
 */
#[ViewsField('commerce_domains_listing_status')]
final class ListingStatus extends FieldPluginBase {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface {
    $status = (string) $this->getValue($values);
    // Selected through the field's views data, once Views has rebuilt it.
    $sold = isset($this->aliases['sold']) ? $this->getValue($values, 'sold') : NULL;
    if ($status === ListingRecorder::SOLD && $sold) {
      return $this->t('Sold on @date', ['@date' => $this->dateFormatter->format((int) $sold, 'short')]);
    }
    return ListingRecorder::statusLabels()[$status] ?? $this->sanitizeValue($status);
  }

}

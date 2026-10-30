<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\field;

use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a domain's status on each marketplace, as the last sync found it.
 *
 * The rows of a whole page are read in one query. A sale says when it was
 * found.
 */
#[ViewsField('commerce_domains_product_listings')]
final class ProductListings extends FieldPluginBase {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The listing recorder.
   *
   * @var \Drupal\commerce_domains_marketplace\ListingRecorder
   */
  protected ListingRecorder $recorder;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The listing rows of this page's products, keyed by product ID.
   *
   * @var array<int, array<string, object>>
   */
  protected array $rows = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->recorder = $container->get(ListingRecorder::class);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * Describes a listing's views, leads, offers and saves, e.g. "12 views".
   *
   * Counts the marketplace doesn't report, and zeros, are left out.
   */
  public static function interest(object $row): string {
    $parts = [];
    foreach (['views', 'leads', 'offers', 'saves'] as $field) {
      $count = (int) ($row->$field ?? 0);
      if ($count > 0) {
        $parts[] = (string) match ($field) {
          'views' => new PluralTranslatableMarkup($count, '1 view', '@count views'),
          'leads' => new PluralTranslatableMarkup($count, '1 lead', '@count leads'),
          'offers' => new PluralTranslatableMarkup($count, '1 offer', '@count offers'),
          'saves' => new PluralTranslatableMarkup($count, '1 save', '@count saves'),
        };
      }
    }
    if (!$parts) {
      return '';
    }
    if (empty($row->stats_days)) {
      return implode(', ', $parts);
    }
    return (string) new TranslatableMarkup('@counts in @days days', [
      '@counts' => implode(', ', $parts),
      '@days' => (int) $row->stats_days,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // The rows come from the product entities, read in preRender().
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values): void {
    $ids = [];
    foreach ($values as $row) {
      if ($product = $this->getEntity($row)) {
        $ids[] = (int) $product->id();
      }
    }
    $this->rows = $ids ? $this->recorder->forProducts($ids) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array|string {
    $product = $this->getEntity($values);
    $rows = $product ? ($this->rows[(int) $product->id()] ?? []) : [];
    if (!$rows) {
      return '';
    }
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple(array_keys($rows));
    $labels = ListingRecorder::statusLabels();
    $items = [];
    foreach ($rows as $provider_id => $row) {
      // Domains that aren't listed and needn't be are left out.
      if ($row->status === ListingRecorder::UNLISTED) {
        continue;
      }
      $status = $labels[$row->status] ?? $row->status;
      if ($row->status === ListingRecorder::SOLD && $row->sold) {
        $status = $this->t('Sold on @date', ['@date' => $this->dateFormatter->format((int) $row->sold, 'short')]);
      }
      $item = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('@provider: @status', [
          '@provider' => isset($providers[$provider_id]) ? $providers[$provider_id]->label() : $provider_id,
          '@status' => $status,
        ]),
        '#attributes' => [
          'class' => ['commerce-domains-listing', 'commerce-domains-listing--' . $row->status],
          'title' => (string) $row->note,
        ],
      ];
      if ($interest = self::interest($row)) {
        $item = [
          'status' => $item,
          'interest' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $interest,
            '#attributes' => ['class' => ['commerce-domains-listing__interest']],
          ],
        ];
      }
      $items[] = $item;
    }
    return $items ? [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['commerce-domains-listings']],
      '#attached' => ['library' => ['commerce_domains_marketplace/listings']],
    ] : '';
  }

}

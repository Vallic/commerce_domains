<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\field;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a recorded listing's settings, described by the marketplace plugin.
 */
#[ViewsField('commerce_domains_listing_summary')]
final class ListingSummary extends FieldPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * One item per setting: yes-or-no settings as a tick or a cross before
   * their name, long text folded away.
   */
  public function render(ResultRow $values): array|string {
    $listing = json_decode((string) $this->getValue($values), TRUE);
    if (!is_array($listing) || !$listing) {
      return '';
    }
    $provider_id = (string) $this->getValue($values, 'provider');
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider_id);
    $plugin = $provider?->getPlugin();

    $items = [];
    foreach ($listing as $field => $value) {
      $field = (string) $field;
      $label = self::fieldLabel($field);
      if (is_bool($value)) {
        $items[] = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          '#attributes' => ['class' => ['cd-listing-summary__flag', $value ? 'cd-listing-summary__flag--on' : 'cd-listing-summary__flag--off']],
          'icon' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $value ? '✓' : '✗',
            '#attributes' => ['class' => ['cd-listing-summary__icon'], 'aria-hidden' => 'true'],
          ],
          'label' => ['#plain_text' => $label],
          'state' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $value ? $this->t('(yes)') : $this->t('(no)'),
            '#attributes' => ['class' => ['visually-hidden']],
          ],
        ];
        continue;
      }
      $described = $plugin instanceof MarketplaceInterface ? $plugin->describeValue($field, $value) : NULL;
      // A plugin that has nothing to add quotes the value back.
      if (is_string($value) && ($described === NULL || $described === var_export($value, TRUE))) {
        $described = $value;
      }
      $described ??= is_scalar($value) ? (string) $value : (string) json_encode($value);
      if (is_string($value) && mb_strlen($value) > 60) {
        $items[] = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          '#attributes' => ['class' => ['cd-listing-summary__text']],
          'details' => [
            '#type' => 'html_tag',
            '#tag' => 'details',
            'summary' => ['#type' => 'html_tag', '#tag' => 'summary', '#value' => $this->t('@label (@count characters)', ['@label' => $label, '@count' => mb_strlen($value)])],
            'text' => ['#type' => 'html_tag', '#tag' => 'p', 'value' => ['#plain_text' => $value]],
          ],
        ];
        continue;
      }
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        'label' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['cd-listing-summary__label']], 'value' => ['#plain_text' => $label]],
        'value' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['cd-listing-summary__value']], 'value' => ['#plain_text' => $described]],
      ];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#attributes' => ['class' => ['cd-listing-summary']],
      '#attached' => ['library' => ['commerce_domains_marketplace/listings']],
      'items' => $items,
    ];
  }

  /**
   * Turns a marketplace's field name into words, e.g. "Floor price".
   */
  protected static function fieldLabel(string $field): string {
    $words = strtolower(trim((string) preg_replace(['/([a-z0-9])([A-Z])/', '/[_-]+/'], ['$1 $2', ' '], $field)));
    return ucfirst($words);
  }

}

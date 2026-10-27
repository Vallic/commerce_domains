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
   */
  public function render(ResultRow $values) {
    $listing = json_decode((string) $this->getValue($values), TRUE);
    if (!is_array($listing) || !$listing) {
      return '';
    }
    $provider_id = (string) $this->getValue($values, 'provider');
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider_id);
    $plugin = $provider?->getPlugin();
    $describe = $plugin instanceof MarketplaceInterface
      ? $plugin->describeValue(...)
      : fn(string $field, mixed $value) => is_scalar($value) ? var_export($value, TRUE) : json_encode($value);
    $parts = array_map(fn(string $field, mixed $value) => "$field " . $describe($field, $value), array_keys($listing), $listing);
    return $this->sanitizeValue(implode(', ', $parts));
  }

}

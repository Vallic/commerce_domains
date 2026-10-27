<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a listing's marketplace by its provider's name.
 */
#[ViewsField('commerce_domains_listing_provider')]
final class ListingProvider extends FieldPluginBase {

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
  public function render(ResultRow $values): MarkupInterface {
    $id = (string) $this->getValue($values);
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($id);
    return $this->sanitizeValue($provider ? (string) $provider->label() : $id);
  }

}

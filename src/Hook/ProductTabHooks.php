<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\Form\DomainTabForm;
use Drupal\commerce_domains\ProductTabs;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The domain tabs beside Edit: forms, and the Edit form without their fields.
 */
class ProductTabHooks {

  public function __construct(
    protected ProductTabs $productTabs,
  ) {}

  /**
   * Implements hook_commerce_domains_product_tabs().
   */
  #[Hook('commerce_domains_product_tabs')]
  public function productTabs(): array {
    return [
      'domain_details' => [
        'title' => new TranslatableMarkup('Details'),
        'path' => 'details',
        'fields' => ['domain_expires', 'domain_registered', 'domain_registrar', 'domain_internal', 'atom_premium'],
        'weight' => 5,
      ],
    ];
  }

  /**
   * Implements hook_entity_type_build().
   */
  #[Hook('entity_type_build')]
  public function entityTypeBuild(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    if (!isset($entity_types['commerce_product'])) {
      return;
    }
    foreach (array_keys($this->productTabs->tabs()) as $operation) {
      $entity_types['commerce_product']->setFormClass($operation, DomainTabForm::class);
    }
  }

  /**
   * Implements hook_entity_form_display_alter().
   *
   * The Edit form leaves out the fields the tabs edit. Adding a domain keeps
   * them, as the tabs only open once it is saved.
   */
  #[Hook('entity_form_display_alter')]
  public function entityFormDisplayAlter(EntityFormDisplayInterface $form_display, array $context): void {
    if ($context['entity_type'] !== 'commerce_product' || $context['form_mode'] !== 'edit') {
      return;
    }
    foreach ($this->productTabs->fields() as $name) {
      $form_display->removeComponent($name);
    }
  }

}

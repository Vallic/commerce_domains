<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Entity;

use Drupal\commerce_domains_owner\Form\RenewalCostForm;
use Drupal\commerce_domains_owner\RenewalCostListBuilder;
use Drupal\commerce_domains_owner\RenewalCosts;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * What a year's renewal of an extension costs, at a registrar or in general.
 *
 * A row in a table of prices, not site configuration: prices change on their
 * own, are imported, and differ between a site and its copies. One row for
 * each extension and registrar (see RenewalCosts).
 */
#[ContentEntityType(
  id: 'commerce_domains_renewal_cost',
  label: new TranslatableMarkup('Renewal cost'),
  label_collection: new TranslatableMarkup('Renewal costs'),
  label_singular: new TranslatableMarkup('renewal cost'),
  label_plural: new TranslatableMarkup('renewal costs'),
  entity_keys: [
    'id' => 'cost_id',
    'uuid' => 'uuid',
    'label' => 'tld',
  ],
  handlers: [
    'list_builder' => RenewalCostListBuilder::class,
    'form' => [
      'default' => RenewalCostForm::class,
      'add' => RenewalCostForm::class,
      'edit' => RenewalCostForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/commerce/domains/renewal-costs/add',
    'edit-form' => '/admin/commerce/domains/renewal-costs/{commerce_domains_renewal_cost}/edit',
    'delete-form' => '/admin/commerce/domains/renewal-costs/{commerce_domains_renewal_cost}/delete',
    'collection' => '/admin/commerce/domains/renewal-costs',
  ],
  admin_permission: 'manage domain portfolio',
  collection_permission: 'view domain portfolio+manage domain portfolio',
  base_table: 'commerce_domains_renewal_cost',
  label_count: [
    'singular' => '@count renewal cost',
    'plural' => '@count renewal costs',
  ],
)]
class RenewalCost extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * Writes an extension as it is stored: com, co.uk.
   */
  public static function normalize(string $tld): string {
    return ltrim(strtolower(trim($tld)), '.');
  }

  /**
   * Whether a normalized extension is written like one.
   */
  public static function isValid(string $tld): bool {
    return (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $tld);
  }

  /**
   * Returns the extension, such as com or co.uk.
   */
  public function getTld(): string {
    return (string) $this->get('tld')->value;
  }

  /**
   * Returns the ID of the registrar; NULL for any registrar.
   */
  public function getProviderId(): ?string {
    return $this->get('provider')->target_id;
  }

  /**
   * Returns what a year costs.
   */
  public function getCost(): ?Price {
    if ($this->get('cost')->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
    $item = $this->get('cost')->first();
    return $item->toPrice();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('tld', self::normalize($this->getTld()));
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    \Drupal::service(RenewalCosts::class)->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service(RenewalCosts::class)->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['tld'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Extension'))
      ->setDescription(new TranslatableMarkup('Such as com or co.uk. A domain takes the longest that it ends with.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);
    $fields['provider'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Registrar'))
      ->setDescription(new TranslatableMarkup("Its cost at this registrar. Without one, it counts for any registrar that doesn't have its own."))
      ->setSetting('target_type', 'commerce_domains_provider')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE);
    $fields['cost'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Cost a year'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'commerce_price_default', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE);
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Updated'));
    return $fields;
  }

}

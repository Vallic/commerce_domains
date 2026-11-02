<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Entity;

use Drupal\commerce_domains_owner\Form\RenewalForm;
use Drupal\commerce_domains_owner\RenewalListBuilder;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A payment that kept a domain registered.
 *
 * What the domain's yearly cost can only estimate: profit and expenses count
 * these first, and estimate only the years they don't cover (see Costs).
 */
#[ContentEntityType(
  id: 'commerce_domains_renewal',
  label: new TranslatableMarkup('Domain renewal'),
  label_collection: new TranslatableMarkup('Renewals paid'),
  label_singular: new TranslatableMarkup('domain renewal'),
  label_plural: new TranslatableMarkup('domain renewals'),
  entity_keys: [
    'id' => 'renewal_id',
    'uuid' => 'uuid',
    'label' => 'domain',
  ],
  handlers: [
    'list_builder' => RenewalListBuilder::class,
    'form' => [
      'default' => RenewalForm::class,
      'add' => RenewalForm::class,
      'edit' => RenewalForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/commerce/domains/expenses/renewals/add',
    'edit-form' => '/admin/commerce/domains/expenses/renewals/{commerce_domains_renewal}/edit',
    'delete-form' => '/admin/commerce/domains/expenses/renewals/{commerce_domains_renewal}/delete',
    'collection' => '/admin/commerce/domains/expenses/renewals',
  ],
  admin_permission: 'manage domain portfolio',
  collection_permission: 'view domain portfolio+manage domain portfolio',
  base_table: 'commerce_domains_renewal',
  label_count: [
    'singular' => '@count domain renewal',
    'plural' => '@count domain renewals',
  ],
)]
class DomainRenewal extends ContentEntityBase implements DomainRenewalInterface {

  /**
   * Returns the types, for people.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by type.
   */
  public static function typeLabels(): array {
    return [
      self::RENEWAL => new TranslatableMarkup('Renewal'),
      self::TRANSFER => new TranslatableMarkup('Transfer'),
      self::RESTORE => new TranslatableMarkup('Restore'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDomain(): string {
    return (string) $this->get('domain')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaid(): int {
    return (int) $this->get('paid')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getYears(): int {
    return max(1, (int) $this->get('years')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getAmount(): ?Price {
    if ($this->get('amount')->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
    $item = $this->get('amount')->first();
    return $item->toPrice();
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return (string) $this->get('type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalId(): string {
    return (string) $this->get('external_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isEstimated(): bool {
    return (bool) $this->get('estimated')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    /** @var \Drupal\commerce_product\Entity\ProductInterface|null $product */
    $product = $this->get('product')->entity;
    if ($product && $product->hasField('domain') && !$product->get('domain')->isEmpty()) {
      $this->set('domain', $product->get('domain')->value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['product'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setDescription(new TranslatableMarkup('The domain product it renewed.'))
      ->setSetting('target_type', 'commerce_product')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);
    $fields['domain'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain name'))
      ->setDescription(new TranslatableMarkup('Kept if the product is deleted.'))
      ->setSetting('max_length', 255);
    $fields['paid'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Paid on'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE);
    $fields['years'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Years'))
      ->setDescription(new TranslatableMarkup('How many years it paid for.'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('min', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE);
    $fields['amount'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setDescription(new TranslatableMarkup('What it cost for all its years, in the currency charged. Leave empty when unknown: the renewal cost counts instead.'))
      ->setDisplayOptions('form', ['type' => 'commerce_price_default', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE);
    $fields['provider'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Registrar'))
      ->setSetting('target_type', 'commerce_domains_provider')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE);
    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::RENEWAL)
      ->setSetting('allowed_values_function', [self::class, 'typeLabels'])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE);
    $fields['estimated'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Estimated'))
      ->setDescription(new TranslatableMarkup('Inferred, for example from the expiry date moving at the registrar. Importing what was charged replaces it.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE);
    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source'))
      ->setDescription(new TranslatableMarkup('Where the record came from: an import, the registrar sync, or empty for by hand.'))
      ->setSetting('max_length', 64);
    $fields['external_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('External ID'))
      ->setDescription(new TranslatableMarkup('Identifies the charge within the domain, so importing a file twice adds nothing.'))
      ->setSetting('max_length', 255);
    return $fields;
  }

}

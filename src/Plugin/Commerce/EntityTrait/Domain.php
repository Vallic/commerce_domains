<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\EntityTrait;

use Drupal\commerce\Attribute\CommerceEntityTrait;
use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;

/**
 * Makes a product type a domain: one product per domain name.
 *
 * The product title is the name as shown to buyers (LearnPhpOnline.com).
 * The domain, label and extension fields are derived from it on every save.
 */
#[CommerceEntityTrait(
  id: 'commerce_domains_domain',
  label: new TranslatableMarkup('Domain name'),
  entity_types: ['commerce_product'],
)]
class Domain extends EntityTraitBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = [];
    $fields['domain'] = BundleFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setDescription(new TranslatableMarkup('The name in lowercase, IDNs as A-labels. Derived from the title.'))
      ->setSetting('max_length', 253);

    $fields['domain_label'] = BundleFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain label'))
      ->setDescription(new TranslatableMarkup('The name without its extension, e.g. "example" in example.co.uk. Derived from the title.'))
      ->setSetting('max_length', 63);

    $fields['domain_extension'] = BundleFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Extension'))
      ->setDescription(new TranslatableMarkup('The public suffix, e.g. "co.uk" in example.co.uk. Derived from the title.'))
      ->setSetting('max_length', 63);

    $fields['domain_registrar'] = BundleFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Registrar'))
      ->setDescription(new TranslatableMarkup('Where the domain is registered.'))
      ->setSetting('target_type', 'commerce_domains_provider')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 10,
      ]);

    $fields['domain_expires'] = BundleFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Expires'))
      ->setDescription(new TranslatableMarkup('When the registration runs out.'))
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 11,
      ]);

    $fields['domain_verified'] = BundleFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Ownership verified'))
      ->setDescription(new TranslatableMarkup('The seller has proven control of the domain.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 12,
        'settings' => ['display_label' => TRUE],
      ]);

    return $fields;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\EntityTrait;

use Drupal\commerce\Attribute\CommerceEntityTrait;
use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;

/**
 * Marks domains that are Atom premium listings.
 *
 * Atom curates and prices those itself. The Atom provider leaves them alone,
 * and other marketplaces list them with their premium price factor.
 */
#[CommerceEntityTrait(
  id: 'commerce_domains_atom_premium',
  label: new TranslatableMarkup('Atom premium listing'),
  entity_types: ['commerce_product'],
)]
class AtomPremium extends EntityTraitBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = [];
    $fields['atom_premium'] = BundleFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Atom premium'))
      ->setDescription(new TranslatableMarkup('Listed and priced by Atom as a premium domain.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 13,
        'settings' => ['display_label' => TRUE],
      ]);
    return $fields;
  }

}

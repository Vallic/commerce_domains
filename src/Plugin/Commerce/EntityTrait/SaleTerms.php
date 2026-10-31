<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\EntityTrait;

use Drupal\commerce\Attribute\CommerceEntityTrait;
use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;

/**
 * Adds how a domain is sold: buy now, offers and lease-to-own.
 *
 * On the variation, next to its price. Empty amounts and terms are worked
 * out from the price, the same way for every marketplace. Two more fields
 * keep a domain for sale on the site but off some or all marketplaces.
 */
#[CommerceEntityTrait(
  id: 'commerce_domains_sale_terms',
  label: new TranslatableMarkup('Domain sale terms'),
  entity_types: ['commerce_product_variation'],
)]
class SaleTerms extends EntityTraitBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = [];
    $fields['sale_buy_now'] = BundleFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Buy now'))
      ->setDescription(new TranslatableMarkup('Sell at the price straight away. Without it, the domain only takes offers.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
        'settings' => ['display_label' => TRUE],
      ]);

    $fields['sale_offers'] = BundleFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Accept offers'))
      ->setDescription(new TranslatableMarkup('Take offers below the price.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 21,
        'settings' => ['display_label' => TRUE],
      ]);

    $fields['sale_minimum_offer'] = BundleFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Minimum offer'))
      ->setDescription(new TranslatableMarkup('The lowest offer taken, before any marketplace price factor. Leave empty to work it out from the price.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 22,
      ]);

    $fields['sale_floor_price'] = BundleFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Floor price'))
      ->setDescription(new TranslatableMarkup('The lowest price a marketplace broker may agree to, before any price factor. Leave empty to work it out from the price.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 23,
      ]);

    $fields['sale_lease_months'] = BundleFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Lease-to-own'))
      ->setDescription(new TranslatableMarkup('The longest lease, in monthly payments; 0 for none. Leave empty to work it out from the price. Marketplaces cap it at what they allow.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 0)
      ->setSetting('max', 60)
      ->setSetting('suffix', ' months')
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 24,
      ]);

    $fields['sale_lease_down_payment'] = BundleFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Lease down payment'))
      ->setDescription(new TranslatableMarkup('The share of the price paid at checkout for a lease-to-own on this site; the rest is paid monthly. Leave empty for the default in the lease settings.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 0)
      ->setSetting('max', 90)
      ->setSetting('suffix', '%')
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 24,
      ]);

    $fields['sale_marketplaces'] = BundleFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('List on marketplaces'))
      ->setDescription(new TranslatableMarkup('Without it, the domain is sold on this site only: marketplace listings are removed and CSV files leave it out.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 25,
        'settings' => ['display_label' => TRUE],
      ]);

    $fields['sale_not_listed_on'] = BundleFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Not listed on'))
      ->setDescription(new TranslatableMarkup('Marketplaces to keep the domain off, while the others list it.'))
      ->setCardinality(BundleFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'commerce_domains_provider')
      ->setSetting('handler', 'commerce_domains_listing:provider')
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 26,
      ]);
    return $fields;
  }

}

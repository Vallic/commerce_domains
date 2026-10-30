<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait;

use Drupal\commerce\Attribute\CommerceEntityTrait;
use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;

/**
 * Adds what a domain costs to hold, whether to renew it, its sale and worth.
 *
 * Together they give the profit of each sold domain; see Costs. The sale
 * fields fill in when the domain sells on the site or a marketplace reports
 * it sold, and can be edited for sales made elsewhere. The appraisal fields
 * are filled in by an appraisal service; see Appraiser.
 */
#[CommerceEntityTrait(
  id: 'commerce_domains_owner_costs',
  label: new TranslatableMarkup('Domain costs and sale'),
  entity_types: ['commerce_product'],
)]
class DomainCosts extends EntityTraitBase {

  public const ID = 'commerce_domains_owner_costs';

  // The fields, which only the owner sees; see ProductHooks.
  public const FIELDS = [
    'domain_purchase_price',
    'domain_purchase_date',
    'domain_renewal_cost',
    'domain_auto_renew',
    'domain_renewal',
    'domain_sale_price',
    'domain_sold',
    'domain_sold_via',
    'domain_lease_months',
    'domain_sale_order',
    'domain_appraisal',
    'domain_appraisal_score',
    'domain_tld_count',
    'domain_appraised',
    'domain_appraisal_notes',
  ];

  // The fields an appraisal fills in; see Appraiser.
  public const APPRAISAL_FIELDS = [
    'domain_appraisal',
    'domain_appraisal_score',
    'domain_tld_count',
    'domain_appraised',
    'domain_appraisal_notes',
  ];

  // The longest lease to own taken, in monthly payments.
  public const MAX_LEASE_MONTHS = 120;

  // The renewal decisions. Without one, the domain is undecided.
  public const KEEP = 'keep';

  public const EXPIRE = 'expire';

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = [];
    $fields['domain_purchase_price'] = BundleFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Purchase price'))
      ->setDescription(new TranslatableMarkup('What the domain cost you: the registration fee, or what you paid for it.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 30,
      ]);

    $fields['domain_purchase_date'] = BundleFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Purchased'))
      ->setDescription(new TranslatableMarkup('When you registered or bought the domain.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_domains_optional_timestamp',
        'weight' => 31,
      ]);

    $fields['domain_renewal_cost'] = BundleFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Renewal cost'))
      ->setDescription(new TranslatableMarkup('What renewing the domain for a year costs at its registrar.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 32,
      ]);

    // No default: empty means the registrar hasn't said.
    $fields['domain_auto_renew'] = BundleFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Auto-renew'))
      ->setDescription(new TranslatableMarkup('The registrar renews the domain automatically. Follows the registrar when its API reports it; turn it on or off at the registrar on the Expiring soon page.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 33,
        'settings' => ['display_label' => TRUE],
      ]);

    $fields['domain_renewal'] = BundleFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Renewal'))
      ->setDescription(new TranslatableMarkup('Keep the domain, or let it expire: it then goes off sale shortly before its expiry date.'))
      ->setSetting('allowed_values', [
        self::KEEP => 'Keep',
        self::EXPIRE => 'Let it expire',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 34,
      ]);

    $fields['domain_sale_price'] = BundleFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Sold for'))
      ->setDescription(new TranslatableMarkup('Filled in from the order when the domain sells on this site. For a sale elsewhere, enter what you received after commission.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 35,
      ]);

    $fields['domain_sold'] = BundleFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Sold'))
      ->setDescription(new TranslatableMarkup('Filled in when the domain sells on this site or a marketplace reports it sold.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_domains_optional_timestamp',
        'weight' => 36,
      ]);

    $fields['domain_sold_via'] = BundleFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Sold through'))
      ->setDescription(new TranslatableMarkup('The marketplace that sold the domain; empty for sales on this site or in private.'))
      ->setSetting('target_type', 'commerce_domains_provider')
      ->setSetting('handler', 'commerce_domains_listing:provider')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 37,
      ]);

    // Empty for a sale paid at once.
    $fields['domain_lease_months'] = BundleFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Lease to own'))
      ->setDescription(new TranslatableMarkup('For a lease to own, the number of monthly payments; Sold for is then what they add up to. Empty for a sale paid at once.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 1)
      ->setSetting('max', self::MAX_LEASE_MONTHS)
      ->setSetting('suffix', ' months')
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 38,
      ]);

    // Set with the sale on this site, so a cancelled order undoes it. Not on
    // the form: an ID typed by hand would point at the wrong order.
    $fields['domain_sale_order'] = BundleFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Sale order'))
      ->setDescription(new TranslatableMarkup('The order the domain sold in on this site.'))
      ->setSetting('unsigned', TRUE);

    // Filled in by Appraiser; shown read-only on the form.
    $fields['domain_appraisal'] = BundleFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Appraisal'))
      ->setDescription(new TranslatableMarkup("An appraisal service's estimate of what the domain is worth."))
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 40,
      ]);

    $fields['domain_appraisal_score'] = BundleFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Appraisal score'))
      ->setDescription(new TranslatableMarkup("The appraisal service's score of the name, out of 10."))
      ->setSetting('precision', 4)
      ->setSetting('scale', 1)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 41,
      ]);

    $fields['domain_tld_count'] = BundleFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Extensions registered'))
      ->setDescription(new TranslatableMarkup('How many extensions the same name is registered in, e.g. 8 when example.com, example.net and six more are taken.'))
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 42,
      ]);

    $fields['domain_appraised'] = BundleFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Appraised'))
      ->setDescription(new TranslatableMarkup('When the appraisal was made.'))
      ->setDisplayOptions('form', [
        'type' => 'commerce_domains_optional_timestamp',
        'weight' => 43,
      ]);

    $fields['domain_appraisal_notes'] = BundleFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Appraisal signals'))
      ->setDescription(new TranslatableMarkup('What the appraisal service says raises or lowers the value, one per line.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 44,
        'settings' => ['rows' => 4],
      ]);

    return $fields;
  }

}

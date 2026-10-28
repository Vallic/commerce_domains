<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Matches Atom premium names, or the other names.
 */
#[CommerceCondition(
  id: 'commerce_domains_order_item_atom_premium',
  label: new TranslatableMarkup('Atom premium'),
  entity_type: 'commerce_order_item',
  display_label: new TranslatableMarkup('Domain is an Atom premium name'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderItemDomainAtomPremium extends DomainConditionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['premium' => TRUE] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['premium'] = [
      '#type' => 'radios',
      '#title' => $this->t('Domains'),
      '#options' => [
        1 => $this->t('Atom premium names'),
        0 => $this->t('Other names'),
      ],
      '#default_value' => (int) $this->configuration['premium'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['premium'] = (bool) $values['premium'];
  }

  /**
   * {@inheritdoc}
   */
  protected function matches(ProductVariationInterface $variation): bool {
    $product = $variation->getProduct();
    $premium = $product->hasField('atom_premium') && (bool) $product->get('atom_premium')->value;
    return $premium === (bool) $this->configuration['premium'];
  }

}

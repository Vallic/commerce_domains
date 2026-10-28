<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Matches domains by how they are sold: buy now, offers, lease-to-own.
 *
 * Each term is required, ruled out, or ignored. Lease-to-own is the
 * domain's own term, or the one worked out from its price.
 */
#[CommerceCondition(
  id: 'commerce_domains_order_item_sale_terms',
  label: new TranslatableMarkup('Sale terms'),
  entity_type: 'commerce_order_item',
  display_label: new TranslatableMarkup('Domain sale terms'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderItemDomainSaleTerms extends DomainConditionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['buy_now' => NULL, 'offers' => NULL, 'lease' => NULL] + parent::defaultConfiguration();
  }

  /**
   * Returns the terms, labelled.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by configuration key.
   */
  protected function terms(): array {
    return [
      'buy_now' => $this->t('Buy now'),
      'offers' => $this->t('Accepts offers'),
      'lease' => $this->t('Lease-to-own'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    foreach ($this->terms() as $key => $label) {
      $value = $this->configuration[$key];
      $form[$key] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => [
          '' => $this->t('Either'),
          'yes' => $this->t('Yes'),
          'no' => $this->t('No'),
        ],
        '#default_value' => $value === NULL ? '' : ($value ? 'yes' : 'no'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if (!array_filter(array_intersect_key($values, $this->terms()))) {
      $form_state->setError($form, $this->t('Choose Yes or No for at least one term.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    foreach (array_keys($this->terms()) as $key) {
      $this->configuration[$key] = match ($values[$key] ?? '') {
        'yes' => TRUE,
        'no' => FALSE,
        default => NULL,
      };
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function matches(ProductVariationInterface $variation): bool {
    $terms = SaleTerms::fromVariation($variation);
    $price = $variation->getPrice();
    $actual = [
      'buy_now' => $terms->buyNow,
      'offers' => $terms->offers,
      'lease' => $terms->buyNow && $price && $terms->leaseMonthsAt((float) $price->getNumber()) > 0,
    ];
    foreach ($actual as $key => $value) {
      if ($this->configuration[$key] !== NULL && $this->configuration[$key] !== $value) {
        return FALSE;
      }
    }
    return TRUE;
  }

}

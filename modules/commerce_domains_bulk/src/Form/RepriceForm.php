<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Form;

use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Multiplies the prices of the selected domains by a factor.
 */
final class RepriceForm extends BulkFormBase {

  /**
   * {@inheritdoc}
   */
  protected function actionId(): string {
    return 'commerce_domains_reprice';
  }

  /**
   * {@inheritdoc}
   */
  protected function edit(): string {
    return BulkEditor::REPRICE;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->products), 'Reprice 1 domain?', 'Reprice @count domains?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Prices in other currencies follow the new prices.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Reprice');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['factor'] = [
      '#type' => 'number',
      '#title' => $this->t('Multiply the prices by'),
      '#description' => $this->t('1.1 raises them by 10%, 0.9 lowers them by 10%.'),
      '#min' => 0.0001,
      '#step' => 'any',
      '#size' => 8,
      '#required' => TRUE,
    ];
    $steps = $this->config(PriceRules::CONFIG)->get('rounding.steps') ?: [];
    $settings = [':settings' => Url::fromRoute('commerce_domains_pricing.settings')->toString()];
    $form['round'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Round with the rounding steps'),
      '#description' => $steps
        ? $this->t('The steps in the <a href=":settings">domain pricing settings</a>. Unticked, prices are only rounded to the currency\'s decimals.', $settings)
        : $this->t('No rounding steps are set in the <a href=":settings">domain pricing settings</a>, so prices are only rounded to the currency\'s decimals.', $settings),
      '#default_value' => (bool) $steps,
    ];
    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Multiply minimum offers and floor prices too'),
      '#description' => $this->t('Where a domain sets its own. Those worked out from the price follow it anyway.'),
      '#default_value' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $factor = $form_state->getValue('factor');
    if (!is_numeric($factor) || (float) $factor <= 0) {
      $form_state->setErrorByName('factor', $this->t('The factor must be more than 0.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function options(FormStateInterface $form_state): array {
    return [
      // As bcmath takes it: no exponent.
      'factor' => Calculator::trim(number_format((float) $form_state->getValue('factor'), 6, '.', '')),
      'round' => (bool) $form_state->getValue('round'),
      'terms' => (bool) $form_state->getValue('terms'),
    ];
  }

}

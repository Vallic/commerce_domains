<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Form;

use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sets the sale terms of the selected domains.
 *
 * Each term is left as it is, or set for every domain.
 */
final class SaleTermsForm extends BulkFormBase {

  /**
   * {@inheritdoc}
   */
  protected function actionId(): string {
    return 'commerce_domains_sale_terms';
  }

  /**
   * {@inheritdoc}
   */
  protected function edit(): string {
    return BulkEditor::SALE_TERMS;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->products), 'Set the sale terms of 1 domain?', 'Set the sale terms of @count domains?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Domains whose variations have no sale terms are left as they are.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Set sale terms');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['buy_now'] = $this->flag($this->t('Buy now'), $this->t('Sell at the price straight away. Without it, the domain only takes offers.'));
    $form['offers'] = $this->flag($this->t('Accept offers'), $this->t('Take offers below the price.'));
    $form['minimum_offer'] = $this->amount('minimum_offer', $this->t('Minimum offer'), $this->t('The lowest offer taken, before any marketplace price factor.'));
    $form['floor_price'] = $this->amount('floor_price', $this->t('Floor price'), $this->t('The lowest price a marketplace broker may agree to, before any price factor.'));
    $form['lease_months'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Lease-to-own'),
      '#description' => $this->t('The longest lease, in monthly payments. Marketplaces cap it at what they allow.'),
      '#tree' => TRUE,
      'mode' => [
        '#type' => 'radios',
        '#title' => $this->t('Lease-to-own'),
        '#title_display' => 'invisible',
        '#options' => [
          'keep' => $this->t('Leave as is'),
          'clear' => $this->t('Work it out from the price'),
          'set' => $this->t('Set to'),
        ],
        '#default_value' => 'keep',
      ],
      'months' => [
        '#type' => 'number',
        '#title' => $this->t('Months'),
        '#description' => $this->t('0 for none.'),
        '#min' => 0,
        '#max' => 60,
        '#field_suffix' => $this->t('months'),
        '#states' => [
          'visible' => [':input[name="lease_months[mode]"]' => ['value' => 'set']],
        ],
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds the choice for a flag: leave as is, yes or no.
   */
  protected function flag(TranslatableMarkup $title, TranslatableMarkup $description): array {
    return [
      '#type' => 'radios',
      '#title' => $title,
      '#description' => $description,
      '#options' => [
        'keep' => $this->t('Leave as is'),
        '1' => $this->t('Yes'),
        '0' => $this->t('No'),
      ],
      '#default_value' => 'keep',
    ];
  }

  /**
   * Builds the choice for an amount: leave as is, work it out or set it.
   */
  protected function amount(string $name, TranslatableMarkup $title, TranslatableMarkup $description): array {
    /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');
    return [
      '#type' => 'fieldset',
      '#title' => $title,
      '#description' => $description,
      '#tree' => TRUE,
      'mode' => [
        '#type' => 'radios',
        '#title' => $title,
        '#title_display' => 'invisible',
        '#options' => [
          'keep' => $this->t('Leave as is'),
          'clear' => $this->t('Work it out from the price'),
          'set' => $this->t('Set to'),
        ],
        '#default_value' => 'keep',
      ],
      'amount' => [
        '#type' => 'commerce_price',
        '#title' => $this->t('Amount'),
        '#default_value' => [
          'number' => '',
          'currency_code' => $store_storage->loadDefault()?->getDefaultCurrencyCode(),
        ],
        '#states' => [
          'visible' => [':input[name="' . $name . '[mode]"]' => ['value' => 'set']],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $missing = fn(string $key, array $value): bool => $form_state->getValue([$key, 'mode']) === 'set'
      && !is_numeric($form_state->getValue([$key, ...$value]));
    foreach (['minimum_offer', 'floor_price'] as $key) {
      if ($missing($key, ['amount', 'number'])) {
        $form_state->setErrorByName($key . '][amount][number', $this->t('Enter the amount to set.'));
      }
    }
    if ($missing('lease_months', ['months'])) {
      $form_state->setErrorByName('lease_months][months', $this->t('Enter the months to set.'));
    }
    $options = $this->options($form_state);
    if ($options['buy_now'] === NULL && $options['offers'] === NULL && $options['minimum_offer']['mode'] === 'keep'
      && $options['floor_price']['mode'] === 'keep' && $options['lease_months']['mode'] === 'keep') {
      $form_state->setErrorByName('buy_now', $this->t('Choose at least one term to change.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function options(FormStateInterface $form_state): array {
    $flag = fn(string $key): ?bool => match ((string) $form_state->getValue($key)) {
      '1' => TRUE,
      '0' => FALSE,
      default => NULL,
    };
    $amount = fn(string $key): array => [
      'mode' => (string) ($form_state->getValue([$key, 'mode']) ?? 'keep'),
      'number' => (string) $form_state->getValue([$key, 'amount', 'number']),
      'currency_code' => (string) $form_state->getValue([$key, 'amount', 'currency_code']),
    ];
    return [
      'buy_now' => $flag('buy_now'),
      'offers' => $flag('offers'),
      'minimum_offer' => $amount('minimum_offer'),
      'floor_price' => $amount('floor_price'),
      'lease_months' => [
        'mode' => (string) ($form_state->getValue(['lease_months', 'mode']) ?? 'keep'),
        'months' => (int) $form_state->getValue(['lease_months', 'months']),
      ],
    ];
  }

}

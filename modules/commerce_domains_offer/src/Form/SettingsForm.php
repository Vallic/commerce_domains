<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for domain offers.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_offer_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_domains_offer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['expire_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days an offer stays open'),
      '#description' => $this->t('Offers nobody answers, and accepted offers nobody pays, expire after this many days without a change. 0 keeps them open.'),
      '#min' => 0,
      '#config_target' => 'commerce_domains_offer.settings:expire_days',
    ];
    $form['notify_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Tell this address about new offers'),
      '#description' => $this->t("Leave empty for the store's email address."),
      '#config_target' => 'commerce_domains_offer.settings:notify_mail',
    ];
    return parent::buildForm($form, $form_state);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for leases to own.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_lease_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_domains_lease.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['default_down_payment'] = [
      '#type' => 'number',
      '#title' => $this->t('Down payment'),
      '#description' => $this->t("The share of the price paid at checkout, for domains whose sale terms set none (Lease down payment on the variation). The rest is paid in the lease's monthly payments."),
      '#min' => 1,
      '#max' => 90,
      '#field_suffix' => '%',
      '#config_target' => 'commerce_domains_lease.settings:default_down_payment',
    ];
    $form['missed'] = [
      '#type' => 'details',
      '#title' => $this->t('Missed payments'),
      '#open' => TRUE,
    ];
    $form['missed']['retry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Try a missed payment again after'),
      '#min' => 1,
      '#max' => 30,
      '#field_suffix' => $this->t('days'),
      '#config_target' => 'commerce_domains_lease.settings:retry_days',
    ];
    $form['missed']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Tries before the lease is in default'),
      '#description' => $this->t('The buyer is told after each failed try. A lease in default is charged no more until you charge it on its page, or cancel it.'),
      '#min' => 0,
      '#max' => 12,
      '#config_target' => 'commerce_domains_lease.settings:max_retries',
    ];
    $form['notify_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Tell this address about missed payments and completed leases'),
      '#description' => $this->t("Leave empty for the site's email address."),
      '#config_target' => 'commerce_domains_lease.settings:notify_mail',
    ];
    return parent::buildForm($form, $form_state);
  }

}

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
    $form['minimum_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Take offers from this price'),
      '#description' => $this->t("In each domain's own currency. Cheaper domains sell at their price only. 0 takes offers at any price."),
      '#min' => 0,
      '#step' => 'any',
      '#config_target' => 'commerce_domains_offer.settings:minimum_price',
    ];
    $form['premium_offers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Take offers on Atom premium names'),
      '#description' => $this->t('Atom sells its premium names at a fixed price.'),
      '#config_target' => 'commerce_domains_offer.settings:premium_offers',
    ];
    $form['notify_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Tell this address about new offers'),
      '#description' => $this->t("Leave empty for the owner's notification address (Commerce Domains Owner), or else the store's email address."),
      '#config_target' => 'commerce_domains_offer.settings:notify_mail',
    ];
    $form['require_phone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Buyers must give a phone number'),
      '#description' => $this->t('With the Phone Number module, buyers pick their country from a list.'),
      '#config_target' => 'commerce_domains_offer.settings:require_phone',
    ];
    return parent::buildForm($form, $form_state);
  }

}

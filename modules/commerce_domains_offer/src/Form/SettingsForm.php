<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Form;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\PaymentLinksInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for domain offers.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager, for the providers.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

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
    $options = [];
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
      if ($provider->status() && $provider->hasCapability(PaymentLinksInterface::class)) {
        $options[$id] = $provider->label();
      }
    }
    $form['payment_link'] = [
      '#type' => 'details',
      '#title' => $this->t('Payment links'),
      '#open' => TRUE,
      '#access' => (bool) $options,
    ];
    $form['payment_link']['payment_link_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Accepted offers are paid through'),
      '#description' => $this->t("A provider's payment link instead of checkout: the buyer pays there, at the agreed price, and the provider hands the domain over. The offer is marked purchased once it reports the payment. Spaceship's SafePay needs the domain listed in SellerHub; when a link can't be made, the offer gets a checkout order as usual."),
      '#options' => $options,
      '#empty_option' => $this->t('- Checkout on this site -'),
      '#empty_value' => '',
      '#config_target' => 'commerce_domains_offer.settings:payment_link_provider',
    ];
    $form['payment_link']['payment_link_buyer_fee'] = [
      '#type' => 'select',
      '#title' => $this->t("Buyer's share of the provider's fee"),
      '#options' => [0 => '0%', 25 => '25%', 50 => '50%', 75 => '75%', 100 => '100%'],
      '#config_target' => 'commerce_domains_offer.settings:payment_link_buyer_fee',
    ];
    return parent::buildForm($form, $form_state);
  }

}

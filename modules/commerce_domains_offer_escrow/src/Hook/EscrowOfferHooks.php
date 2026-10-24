<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer_escrow\Hook;

use Drupal\commerce\Context;
use Drupal\commerce_domains_offer\Form\OfferForm;
use Drupal\commerce_domains_offer\OfferPolicy;
use Drupal\commerce_domains_offer_escrow\EscrowOfferStarter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adds the Escrow.com choice to the offer form and its settings.
 */
class EscrowOfferHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EscrowOfferStarter $starter,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for commerce_domains_offer_form.
   *
   * Only for prices in a currency Escrow.com transacts in.
   */
  #[Hook('form_commerce_domains_offer_form_alter')]
  public function offerFormAlter(array &$form, FormStateInterface $form_state): void {
    $currency = (string) $form_state->get('currency');
    if (!isset($form['amount']) || !$this->starter->supports($currency)) {
      return;
    }
    $form['escrow'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negotiate on Escrow.com'),
      '#description' => $this->t('Make your offer on Escrow.com: the negotiation, the payment and the transfer then all run through Escrow.com.'),
      '#default_value' => (bool) $this->configFactory->get('commerce_domains_offer_escrow.settings')->get('preselect'),
      '#weight' => -10,
    ];
    // The buyer names the amount on Escrow.com.
    $form['amount']['#required'] = FALSE;
    $form['amount']['#states'] = [
      'visible' => [':input[name="escrow"]' => ['checked' => FALSE]],
      'required' => [':input[name="escrow"]' => ['checked' => FALSE]],
    ];
    $form['#validate'] = [[static::class, 'validateOffer'], '::validateForm'];
    $form['#submit'] = ['::submitForm', [static::class, 'submitOffer']];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for commerce_domains_offer_settings.
   */
  #[Hook('form_commerce_domains_offer_settings_alter')]
  public function settingsFormAlter(array &$form, FormStateInterface $form_state): void {
    $gateways = [];
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway */
    foreach ($this->entityTypeManager->getStorage('commerce_payment_gateway')->loadByProperties(['plugin' => 'escrow_offer']) as $gateway) {
      $gateways[$gateway->id()] = $gateway->label();
    }
    $form['escrow'] = [
      '#type' => 'details',
      '#title' => $this->t('Escrow.com'),
      '#open' => TRUE,
    ];
    $form['escrow']['escrow_gateway'] = [
      '#type' => 'select',
      '#title' => $this->t('Escrow Offer gateway'),
      '#options' => $gateways,
      '#empty_option' => $this->t('- The first enabled one -'),
      '#config_target' => 'commerce_domains_offer_escrow.settings:gateway',
    ];
    $form['escrow']['escrow_preselect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negotiate on Escrow.com by default'),
      '#config_target' => 'commerce_domains_offer_escrow.settings:preselect',
    ];
  }

  /**
   * Validation handler: an Escrow.com offer needs no amount here.
   */
  public static function validateOffer(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('escrow')) {
      $form_state->set(OfferForm::ELSEWHERE, TRUE);
    }
  }

  /**
   * Submission handler: starts the negotiation and sends the buyer to it.
   */
  public static function submitOffer(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->get(OfferForm::ELSEWHERE)) {
      return;
    }
    $variation = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->load($form_state->get('variation'));
    if (!$variation instanceof ProductVariationInterface) {
      return;
    }
    $account = \Drupal::currentUser();
    $policy = \Drupal::service(OfferPolicy::class);
    $store = $policy->store($variation);
    $amount = NULL;
    if ((string) $form_state->getValue('amount') !== '' && ($price = $policy->price($variation, new Context($account, $store)))) {
      $amount = new Price((string) $form_state->getValue('amount'), $price->getCurrencyCode());
    }
    \Drupal::service(FloodInterface::class)->register(OfferForm::FLOOD_EVENT);
    try {
      $url = \Drupal::service(EscrowOfferStarter::class)->start($variation, [
        'mail' => $form_state->getValue('mail'),
        'name' => $form_state->getValue('name'),
        'phone' => $form_state->getValue('phone'),
        'message' => $form_state->getValue('message'),
      ], $account, $store, $amount);
    }
    catch (\Exception) {
      \Drupal::messenger()->addError(new TranslatableMarkup('Escrow.com could not start the negotiation. Please try again, or make your offer here instead.'));
      $form_state->setRebuild();
      return;
    }
    $form_state->setResponse(new TrustedRedirectResponse($url));
  }

}

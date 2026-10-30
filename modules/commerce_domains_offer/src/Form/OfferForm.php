<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Form;

use Drupal\commerce\Context;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_offer\OfferPolicy;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The form buyers make an offer on a domain with.
 *
 * Offers are in the currency of the price the buyer sees, and must reach the
 * domain's minimum offer.
 */
final class OfferForm extends FormBase {

  // Offers one visitor may make in an hour, to keep spam down.
  protected const FLOOD_LIMIT = 5;

  public const FLOOD_EVENT = 'commerce_domains_offer.submit';

  // Set in form state by an integration that takes the offer to another
  // channel: the amount is then optional, and the form records nothing.
  public const ELSEWHERE = 'commerce_domains_offer_elsewhere';

  public function __construct(
    protected OfferPolicy $offerPolicy,
    protected OfferManager $offerManager,
    protected FloodInterface $flood,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ElementInfoManagerInterface $elementInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OfferPolicy::class),
      $container->get(OfferManager::class),
      $container->get('flood'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.element_info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_offer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProductVariationInterface $variation = NULL): array {
    // Whether the form shows follows the offer settings too.
    CacheableMetadata::createFromRenderArray($form)->merge($this->offerPolicy->cacheability())->applyTo($form);
    $store = $variation ? $this->offerPolicy->store($variation) : NULL;
    $price = $store && $this->offerPolicy->allows($variation) ? $this->offerPolicy->price($variation, new Context($this->currentUser(), $store)) : NULL;
    if (!$price) {
      return $form;
    }
    $minimum = $this->offerPolicy->minimum($variation, $price);
    $form_state->set('variation', $variation->id());
    $form_state->set('currency', $price->getCurrencyCode());
    $form_state->set('minimum', $minimum->getNumber());

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Your offer'),
      '#field_suffix' => $price->getCurrencyCode(),
      '#min' => (float) $minimum->getNumber(),
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#default_value' => $this->currentUser()->isAuthenticated() ? $this->currentUser()->getDisplayName() : '',
    ];
    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email'),
      '#required' => TRUE,
      '#default_value' => $this->currentUser()->getEmail(),
    ];
    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone'),
      '#maxlength' => 64,
      '#required' => (bool) $this->config('commerce_domains_offer.settings')->get('require_phone'),
    ];
    // The Phone Number module adds a country list, and checks the number.
    if ($this->elementInfo->hasDefinition('phone_number')) {
      $form['phone']['#type'] = 'phone_number';
      unset($form['phone']['#maxlength']);
    }
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#rows' => 3,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Make an offer'),
    ];
    $form['#cache']['contexts'][] = 'user';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT)) {
      $form_state->setErrorByName('', $this->t('You have made too many offers in the last hour. Please try again later.'));
      return;
    }
    $variation = $this->variation($form_state);
    if (!$variation || !$this->offerPolicy->allows($variation)) {
      $form_state->setErrorByName('', $this->t('This domain no longer takes offers.'));
      return;
    }
    if ($form_state->get(self::ELSEWHERE)) {
      return;
    }
    if ((string) $form_state->getValue('amount') === '') {
      $form_state->setErrorByName('amount', $this->t('Enter your offer.'));
      return;
    }
    $amount = (float) $form_state->getValue('amount');
    $minimum = (float) $form_state->get('minimum');
    if ($amount < $minimum) {
      $form_state->setErrorByName('amount', $this->t('Offers start at @minimum @currency.', [
        '@minimum' => number_format($minimum),
        '@currency' => $form_state->get('currency'),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $variation = $this->variation($form_state);
    if (!$variation || $form_state->get(self::ELSEWHERE)) {
      return;
    }
    $this->flood->register(self::FLOOD_EVENT);
    $amount = new Price((string) $form_state->getValue('amount'), (string) $form_state->get('currency'));
    $offer = $this->offerManager->submit($variation, $amount, [
      'mail' => $form_state->getValue('mail'),
      'name' => $form_state->getValue('name'),
      'phone' => self::phone($form_state),
      'message' => $form_state->getValue('message'),
    ], $this->currentUser(), $this->offerPolicy->store($variation));
    $this->messenger()->addStatus($this->t('Thank you, your offer for @domain has been sent. We will answer by email at @mail.', [
      '@domain' => $offer->getDomain(),
      '@mail' => $offer->getEmail(),
    ]));
  }

  /**
   * Returns the phone number the buyer gave, in full.
   *
   * The Phone Number module's element gives the number with its country
   * code, under "value".
   */
  public static function phone(FormStateInterface $form_state): string {
    $phone = $form_state->getValue('phone');
    return trim((string) (is_array($phone) ? ($phone['value'] ?? '') : $phone));
  }

  /**
   * Returns the variation the form is for.
   */
  protected function variation(FormStateInterface $form_state): ?ProductVariationInterface {
    $id = $form_state->get('variation');
    $variation = $id ? $this->entityTypeManager->getStorage('commerce_product_variation')->load($id) : NULL;
    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

}

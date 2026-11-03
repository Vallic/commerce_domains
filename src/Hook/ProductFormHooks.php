<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\Controller\RegistrarTabController;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains\SaleTermRules;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Locks a registrar's details, and says what empty sale terms come to.
 */
class ProductFormHooks {

  use StringTranslationTrait;

  public function __construct(
    protected RegistrarSync $registrarSync,
  ) {}

  /**
   * Implements hook_field_widget_complete_form_alter().
   *
   * Says under an empty-able sale term what leaving it empty comes to, on any
   * form that edits a variation.
   */
  #[Hook('field_widget_complete_form_alter')]
  public function saleTermHint(array &$field_widget_complete_form, FormStateInterface $form_state, array $context): void {
    $items = $context['items'];
    $variation = $items->getEntity();
    if (!$variation instanceof ProductVariationInterface) {
      return;
    }
    $hint = $this->fallbackHint($items->getName(), $variation->getPrice());
    if ($hint === NULL) {
      return;
    }
    $page = Url::fromRoute('commerce_domains.sale_terms');
    $field_widget_complete_form['fallback'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['description']],
      '#value' => $page->access()
        ? $this->t('@hint <a href=":url">Sale terms</a>', ['@hint' => $hint, ':url' => $page->toString()])
        : $hint,
    ];
  }

  /**
   * Returns what an empty sale term comes to at a price.
   *
   * @param string $field
   *   The field's name.
   * @param \Drupal\commerce_price\Price|null $price
   *   The variation's price, if it has one yet.
   *
   * @return \Stringable|null
   *   The text; NULL for other fields.
   */
  protected function fallbackHint(string $field, ?Price $price): ?\Stringable {
    $amount = fn(float $number) => number_format($number, 0) . ' ' . $price?->getCurrencyCode();
    $number = (float) $price?->getNumber();
    if ($field === 'sale_lease_months') {
      if (!SaleTerms::automaticLease()) {
        return $this->t('If empty: no lease, unless a marketplace has leases on in its own sale terms.');
      }
      if (!$price) {
        return $this->t('If empty: worked out from the price.');
      }
      $months = SaleTermRules::general()->leaseMonths($number);
      return $months
        ? $this->t("If empty: up to @months months at this price, within each marketplace's own terms.", ['@months' => $months])
        : $this->t('If empty: no lease at this price.');
    }
    if ($field === 'sale_minimum_offer') {
      return $price
        ? $this->t("If empty: @amount at this price, unless a marketplace's own terms differ.", ['@amount' => $amount(SaleTerms::defaultMinimumOffer($number))])
        : $this->t('If empty: worked out from the price.');
    }
    if ($field === 'sale_floor_price') {
      return $price
        ? $this->t("If empty: @amount at this price on marketplaces with a floor, unless their own terms differ.", ['@amount' => $amount(SaleTerms::defaultFloorPrice($number))])
        : $this->t('If empty: worked out from the price.');
    }
    return NULL;
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for commerce_product_form.
   */
  #[Hook('form_commerce_product_form_alter')]
  public function productFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    $product = $form_object->getEntity();
    if (!$product instanceof ProductInterface || $product->isNew() || !($provider = $this->registrarSync->registrationSource($product))) {
      return;
    }
    $args = ['@registrar' => $provider->label()];
    // The registrar's API holds the domain; after a transfer away, the new
    // registrar's sync records the move.
    if (isset($form['domain_registrar'])) {
      $form['domain_registrar']['#disabled'] = TRUE;
      $form['domain_registrar']['widget']['#description'] = $this->t("@registrar's API holds the domain. After a transfer, the new registrar's sync records the move.", $args);
    }
    // Until the registrar's details are applied, a field stays editable. A
    // disabled element keeps its stored value, whatever is submitted.
    foreach (['domain_expires', 'domain_registered'] as $field) {
      if (isset($form[$field]) && !$product->get($field)->isEmpty()) {
        $form[$field]['#disabled'] = TRUE;
        $form[$field]['widget'][0]['value']['#description'] = $this->t("From @registrar's registration details. Refresh them on the provider's Registrations tab.", $args);
      }
    }
    // The Registrar tab only shows what the registrar reports: edited by
    // hand only for registrars without an API, or one not holding it.
    if ($form_object->getOperation() === RegistrarTabController::TAB) {
      foreach (['domain_registrar', 'domain_expires', 'domain_registered'] as $field) {
        if (isset($form[$field])) {
          $form[$field]['#disabled'] = TRUE;
        }
      }
      $form['actions']['#access'] = FALSE;
      $form['read_from'] = [
        '#type' => 'item',
        '#markup' => $this->t("Read from @registrar's API, which holds the domain; the registrar's sync keeps it up to date.", $args),
        '#weight' => -100,
      ];
    }
    if (isset($form['domain_auto_renew']) && !$product->get('domain_auto_renew')->isEmpty()) {
      $form['domain_auto_renew']['#disabled'] = TRUE;
      $form['domain_auto_renew']['widget']['value']['#description'] = $this->t("From @registrar's registration details. Turn it on or off at the registrar on the Expiring soon page.", $args);
    }
  }

}

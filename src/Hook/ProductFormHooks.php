<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Locks the expiry on domains whose registrar API keeps it.
 */
class ProductFormHooks {

  use StringTranslationTrait;

  public function __construct(
    protected RegistrarSync $registrarSync,
  ) {}

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for commerce_product_form.
   */
  #[Hook('form_commerce_product_form_alter')]
  public function productFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!isset($form['domain_expires']) || !$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    $product = $form_object->getEntity();
    if (!$product instanceof ProductInterface || $product->isNew() || !($provider = $this->registrarSync->registrationSource($product))) {
      return;
    }
    // Until the registrar's details are applied, the date stays editable.
    if ($product->get('domain_expires')->isEmpty()) {
      return;
    }
    // A disabled element keeps its stored value, whatever is submitted.
    $form['domain_expires']['#disabled'] = TRUE;
    $form['domain_expires']['widget'][0]['value']['#description'] = $this->t("From @registrar's registration details. Refresh them on the provider's Registrations tab.", [
      '@registrar' => $provider->label(),
    ]);
  }

}

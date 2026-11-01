<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\Controller\RegistrarTabController;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Locks the registrar's details on domains whose registrar API keeps them.
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

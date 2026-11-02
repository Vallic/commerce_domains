<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits a renewal paid by hand.
 *
 * Reached from a domain's Costs tab (?product=), it is for that domain: there
 * is nothing to pick, and saving goes back to the tab.
 */
class RenewalForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $product = $this->productFromUrl();
    if ($product && $this->entity->get('product')->isEmpty()) {
      $this->entity->set('product', $product->id());
    }
    $form = parent::form($form, $form_state);
    if ($product || !$this->entity->isNew()) {
      $form['product']['#access'] = FALSE;
      $domain = $product?->label() ?? $this->entity->label();
      $form['#title'] = $this->entity->isNew()
        ? $this->t('Add a renewal of %domain', ['%domain' => $domain])
        : $this->t('Edit the renewal of %domain', ['%domain' => $domain]);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $saved = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the renewal of %domain.', ['%domain' => $this->entity->label()]));
    $product = $this->productFromUrl();
    if ($product) {
      $form_state->setRedirect('commerce_domains.product_tab.domain_costs', ['commerce_product' => $product->id()]);
    }
    else {
      $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    }
    return $saved;
  }

  /**
   * Returns the domain the URL names, for a renewal being added.
   */
  protected function productFromUrl(): ?ProductInterface {
    $id = $this->getRequest()->query->getInt('product');
    if (!$id || !$this->entity->isNew() && $this->entity->get('product')->target_id != $id) {
      return NULL;
    }
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($id);
    return $product instanceof ProductInterface && $product->hasField('domain') && !$product->get('domain')->isEmpty() ? $product : NULL;
  }

}

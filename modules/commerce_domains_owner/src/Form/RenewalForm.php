<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits a renewal paid by hand; ?product= starts with that domain.
 */
class RenewalForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $product = $this->getRequest()->query->getInt('product');
    if ($product && $this->entity->isNew() && $this->entity->get('product')->isEmpty()) {
      $this->entity->set('product', $product);
    }
    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $saved = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the renewal of %domain.', ['%domain' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $saved;
  }

}

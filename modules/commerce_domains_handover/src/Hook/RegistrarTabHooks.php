<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Hook;

use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Points a sold domain's Registrar tab to its handover.
 */
class RegistrarTabHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for the domain's Registrar tab.
   *
   * A seller who opens the registrar side first learns that the domain is
   * being handed over, and where: the handover emails the auth code to the
   * buyer, instead of showing it.
   */
  #[Hook('form_commerce_domains_domain_registrar_alter')]
  public function registrarFormAlter(array &$form, FormStateInterface $form_state): void {
    $form['#cache']['tags'][] = 'commerce_domains_handover_list';
    $product_id = $form_state->get('product');
    if (!$product_id) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_domains_handover');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('product_id', $product_id)
      ->sort('created', 'DESC')
      ->execute();
    $handover = NULL;
    foreach ($storage->loadMultiple($ids) as $candidate) {
      if ($candidate instanceof HandoverInterface && $candidate->isOpen() && $candidate->getOrderId()) {
        $handover = $candidate;
        break;
      }
    }
    if (!$handover) {
      return;
    }
    $url = Url::fromRoute('commerce_domains_handover.order', ['commerce_order' => $handover->getOrderId()]);
    if (!$url->access()) {
      return;
    }
    $form['handover'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#weight' => -100,
      'text' => [
        '#markup' => $this->t('@domain is sold and waiting to be handed over: take the steps on <a href=":url">its order\'s Handover tab</a>, which emails the auth code to the buyer.', [
          '@domain' => $handover->getDomain(),
          ':url' => $url->toString(),
        ]),
      ],
    ];
  }

}

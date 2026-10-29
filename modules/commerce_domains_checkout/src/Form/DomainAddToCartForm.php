<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\Form;

use Drupal\commerce_cart\Form\AddToCartForm;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Buys one domain: the cart holds only it, and checkout opens at once.
 *
 * A domain sells once, so a cart of several would only collect names the
 * buyer looked at earlier. Domains whose sale terms leave out buy now are
 * sold by offer only, and can't be bought here.
 */
class DomainAddToCartForm extends AddToCartForm {

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Buy now');
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $this->entity;
    $actions['submit']['#access'] = self::buyNow($order_item->getPurchasedEntity());
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = parent::validateForm($form, $form_state);
    $variation = $order_item->getPurchasedEntity();
    if (!self::buyNow($variation)) {
      $form_state->setErrorByName('', $this->t('%domain is sold by offer only.', ['%domain' => $variation?->getOrderItemTitle()]));
    }
    return $order_item;
  }

  /**
   * Returns whether a purchasable entity can be bought at its price.
   */
  protected static function buyNow(mixed $variation): bool {
    return !$variation instanceof ProductVariationInterface || SaleTerms::fromVariation($variation)->buyNow;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $this->entity;
    $purchased_entity = $order_item->getPurchasedEntity();
    $cart = $this->cartProvider->getCart($this->orderTypeResolver->resolve($order_item), $this->selectStore($purchased_entity));
    // The cart stays, emptied, for the domain to go in.
    if ($cart) {
      $this->cartManager->emptyCart($cart);
    }
    parent::submitForm($form, $form_state);
    $form_state->setRedirect('commerce_checkout.form', ['commerce_order' => $form_state->get('cart_id')]);
  }

}

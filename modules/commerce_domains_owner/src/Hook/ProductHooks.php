<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Groups the cost fields on the product form, and follows decisions.
 */
class ProductHooks {

  use StringTranslationTrait;

  // The fields of the costs trait, by the form group they go in.
  protected const GROUPS = [
    'domain_costs' => [
      'domain_purchase_price',
      'domain_purchase_date',
      'domain_renewal_cost',
      'domain_auto_renew',
      'domain_renewal',
    ],
    'domain_sale' => ['domain_sale_price', 'domain_sold', 'domain_sold_via', 'domain_lease_months'],
  ];

  public function __construct(
    protected Renewals $renewals,
  ) {}

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for commerce_product_form.
   */
  #[Hook('form_commerce_product_form_alter')]
  public function productFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['domain_purchase_price'])) {
      return;
    }
    $form_object = $form_state->getFormObject();
    $product = $form_object instanceof ContentEntityFormInterface ? $form_object->getEntity() : NULL;
    $sold = $product instanceof ProductInterface && $product->hasField('domain_sold') && !$product->get('domain_sold')->isEmpty();
    $form['domain_costs'] = [
      '#type' => 'details',
      '#title' => $this->t('Costs and renewal'),
      '#description' => $this->t('What the domain costs to hold, and whether to renew it. They give the profit when it sells.'),
      '#open' => TRUE,
      '#weight' => 30,
    ];
    $form['domain_sale'] = [
      '#type' => 'details',
      '#title' => $this->t('Sale'),
      '#open' => $sold,
      '#weight' => 31,
    ];
    foreach (self::GROUPS as $group => $fields) {
      // Without access to its fields, a group would show empty.
      $form[$group]['#access'] = FALSE;
      foreach ($fields as $field) {
        if (isset($form[$field])) {
          $form[$field]['#group'] = $group;
          $form[$group]['#access'] = $form[$group]['#access'] || ($form[$field]['#access'] ?? TRUE);
        }
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for commerce_product.
   *
   * Puts a domain kept again back on sale, however the decision changed.
   */
  #[Hook('commerce_product_presave')]
  public function productPresave(ProductInterface $product): void {
    $this->renewals->restore($product);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for commerce_product.
   */
  #[Hook('commerce_product_delete')]
  public function productDelete(ProductInterface $product): void {
    $this->renewals->forget($product);
  }

}

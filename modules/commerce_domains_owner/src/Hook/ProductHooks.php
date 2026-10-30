<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Groups the cost fields on the product form, and follows decisions.
 *
 * A sale entered by hand takes the domain off sale.
 */
class ProductHooks {

  use StringTranslationTrait;

  // The domain's own fields and those of the costs trait, by the form group
  // they go in.
  protected const GROUPS = [
    'domain_details' => ['domain_expires', 'domain_registrar', 'domain_internal', 'atom_premium'],
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
    protected Availability $availability,
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
    // A tab each, the sale first once the domain sold.
    $form['domain_owner'] = [
      '#type' => 'vertical_tabs',
      '#weight' => 30,
      '#default_tab' => $sold ? 'edit-domain-sale' : 'edit-domain-details',
    ];
    $form['domain_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Domain details'),
      '#description' => $this->t('Where the domain is registered and until when, and whether it is kept for your own use.'),
      '#group' => 'domain_owner',
      '#weight' => 29,
    ];
    $form['domain_costs'] = [
      '#type' => 'details',
      '#title' => $this->t('Costs and renewal'),
      '#description' => $this->t('What the domain costs to hold, and whether to renew it. They give the profit when it sells.'),
      '#group' => 'domain_owner',
      '#weight' => 30,
    ];
    $form['domain_sale'] = [
      '#type' => 'details',
      '#title' => $this->t('Sale'),
      '#description' => $this->t('Leave it empty until the domain sells: a sold date takes it off sale.'),
      '#group' => 'domain_owner',
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
    $form['domain_owner']['#access'] = $form['domain_details']['#access'] || $form['domain_costs']['#access'] || $form['domain_sale']['#access'];
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
   * Implements hook_ENTITY_TYPE_update() for commerce_product.
   *
   * Takes a domain off sale once its sale is entered by hand, so the
   * marketplace syncs its save queued remove its listings. Sales recorded
   * from an order or a marketplace leave that to them.
   */
  #[Hook('commerce_product_update')]
  public function productUpdate(ProductInterface $product): void {
    if ($product->isSyncing() || !$product->hasField('domain_sold') || $product->get('domain_sold')->isEmpty()) {
      return;
    }
    $original = $product->getOriginal();
    if ($original instanceof ProductInterface && $original->get('domain_sold')->isEmpty()) {
      $this->availability->markProductSold($product);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for commerce_product.
   */
  #[Hook('commerce_product_delete')]
  public function productDelete(ProductInterface $product): void {
    $this->renewals->forget($product);
  }

}

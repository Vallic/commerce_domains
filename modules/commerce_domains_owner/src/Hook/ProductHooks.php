<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Groups the cost fields on the product form, and follows decisions.
 *
 * A sale entered by hand takes the domain off sale. A sale needs what it
 * sold for: a sold date without it is no sale, and is cleared on save.
 */
class ProductHooks {

  use StringTranslationTrait;

  // The domain tabs this module adds beside Edit, with the fields of the
  // costs trait each edits; see ProductTabs.
  public const TABS = [
    'domain_costs' => [
      'domain_purchase_price',
      'domain_purchase_date',
      'domain_renewal_cost',
      'domain_auto_renew',
      'domain_renewal',
    ],
    'domain_sale' => ['domain_sale_price', 'domain_sold', 'domain_sold_via', 'domain_lease_months'],
    'domain_valuation' => DomainCosts::APPRAISAL_FIELDS,
  ];

  public function __construct(
    protected Renewals $renewals,
    protected Availability $availability,
  ) {}

  /**
   * Implements hook_commerce_domains_product_tabs().
   */
  #[Hook('commerce_domains_product_tabs')]
  public function productTabs(): array {
    return [
      'domain_costs' => [
        'title' => $this->t('Costs'),
        'path' => 'costs',
        'fields' => self::TABS['domain_costs'],
        'weight' => 6,
      ],
      'domain_sale' => [
        'title' => $this->t('Sale'),
        'path' => 'sale',
        'fields' => self::TABS['domain_sale'],
        'weight' => 7,
      ],
      'domain_valuation' => [
        'title' => $this->t('Valuation'),
        'path' => 'valuation',
        'fields' => self::TABS['domain_valuation'],
        'weight' => 8,
        'read_only' => TRUE,
      ],
    ];
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for commerce_product_form.
   *
   * On any product form with the costs fields: appraisals are shown, not
   * edited, and a sold date needs the price. The Sale tab can undo a sale
   * entered by hand or found on a marketplace.
   */
  #[Hook('form_commerce_product_form_alter')]
  public function productFormAlter(array &$form, FormStateInterface $form_state): void {
    // Appraisals aren't edited: a disabled element keeps its stored value.
    foreach (DomainCosts::APPRAISAL_FIELDS as $field) {
      if (isset($form[$field])) {
        $form[$field]['#disabled'] = TRUE;
      }
    }
    if (isset($form['domain_sold'])) {
      $form['#validate'][] = [static::class, 'validateSale'];
    }
    $form_object = $form_state->getFormObject();
    $product = $form_object instanceof ContentEntityFormInterface ? $form_object->getEntity() : NULL;
    if (!$product instanceof ProductInterface || $form_object->getOperation() !== 'domain_sale' || $product->get('domain_sold')->isEmpty()) {
      return;
    }
    $order_id = $product->get('domain_sale_order')->value;
    if ($order_id) {
      $form['undo_note'] = [
        '#type' => 'item',
        '#markup' => $this->t('Sold through order @order on this site: cancel the order to undo the sale.', ['@order' => $order_id]),
        '#weight' => 90,
      ];
      return;
    }
    $form['actions']['undo'] = [
      '#type' => 'submit',
      '#value' => $this->t('Undo sale'),
      '#submit' => [[static::class, 'undoSale']],
      '#limit_validation_errors' => [],
      '#button_type' => 'danger',
      '#weight' => 20,
    ];
  }

  /**
   * Submit handler: clears the sale and puts the domain back on sale.
   */
  public static function undoSale(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    $product = $form_object instanceof ContentEntityFormInterface ? $form_object->getEntity() : NULL;
    if (!$product instanceof ProductInterface) {
      return;
    }
    $product = \Drupal::entityTypeManager()->getStorage('commerce_product')->loadUnchanged($product->id());
    if (!$product instanceof ProductInterface) {
      return;
    }
    \Drupal::service(SaleRecorder::class)->undo($product);
    \Drupal::messenger()->addStatus(new TranslatableMarkup('The sale of %domain is undone, and it is back on sale.', ['%domain' => $product->label()]));
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for commerce_product.
   *
   * Puts a domain kept again back on sale, however the decision changed,
   * and clears a sale without what it sold for, however it was set.
   */
  #[Hook('commerce_product_presave')]
  public function productPresave(ProductInterface $product): void {
    $this->renewals->restore($product);
    if (self::saleWithoutPrice($product)) {
      foreach (['domain_sold', 'domain_sold_via', 'domain_lease_months'] as $field) {
        if ($product->hasField($field)) {
          $product->set($field, NULL);
        }
      }
    }
  }

  /**
   * Whether a domain has a sold date but not what it sold for.
   */
  public static function saleWithoutPrice(ProductInterface $product): bool {
    return $product->hasField('domain_sold') && $product->hasField('domain_sale_price')
      && !$product->get('domain_sold')->isEmpty() && $product->get('domain_sale_price')->isEmpty();
  }

  /**
   * Form validation: a sold date needs what the domain sold for.
   */
  public static function validateSale(array &$form, FormStateInterface $form_state): void {
    $sold = $form_state->getValue(['domain_sold', 0, 'value']);
    $price = $form_state->getValue(['domain_sale_price', 0, 'number']);
    if (!empty($sold) && ($price === NULL || $price === '') && isset($form['domain_sale_price'])) {
      $form_state->setErrorByName('domain_sale_price', new TranslatableMarkup('Enter what the domain sold for: without it, there is no sale.'));
    }
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

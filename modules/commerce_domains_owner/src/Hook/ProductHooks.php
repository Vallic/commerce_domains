<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Entity\DomainRenewal;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_domains_owner\RenewalHistory;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

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
    'domain_sale' => [
      'domain_sale_price',
      'domain_sale_commission',
      'domain_sold',
      'domain_sold_via',
      'domain_lease_months',
    ],
    'domain_valuation' => DomainCosts::APPRAISAL_FIELDS,
  ];

  public function __construct(
    protected Renewals $renewals,
    protected Availability $availability,
    protected Costs $costs,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected RenewalHistory $history,
    protected TimeInterface $time,
  ) {}

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
    if ($product instanceof ProductInterface && $form_object->getOperation() === 'domain_costs') {
      $form['renewals_paid'] = $this->renewalsPaid($product);
    }
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
   * Builds the list of a domain's renewals paid, for its Costs tab.
   */
  protected function renewalsPaid(ProductInterface $product): array {
    $rows = [];
    foreach (array_reverse($this->history->forProduct($product)) as $renewal) {
      $amount = $renewal->getAmount();
      $money = $amount ? $this->currencyFormatter->format($amount->getNumber(), $amount->getCurrencyCode()) : '';
      $rows[] = [
        date('Y-m-d', $renewal->getPaid()),
        DomainRenewal::typeLabels()[$renewal->getType()] ?? $renewal->getType(),
        $renewal->getYears(),
        $renewal->isEstimated() ? $this->t('@amount (estimated)', ['@amount' => $money]) : $money,
        $renewal->get('provider')->entity?->label() ?? '',
      ];
    }
    $add = Url::fromRoute('entity.commerce_domains_renewal.add_form', [], ['query' => ['product' => $product->id()]]);
    return [
      '#type' => 'details',
      '#title' => $this->t('Renewals paid'),
      '#open' => (bool) $rows,
      '#weight' => 90,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Paid on'),
          $this->t('Type'),
          $this->t('Years'),
          $this->t('Amount'),
          $this->t('Registrar'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('None recorded: the renewals of this domain are estimated from its yearly cost.'),
      ],
      'add' => [
        '#type' => 'link',
        '#title' => $this->t('Add a renewal'),
        '#url' => $add,
        '#access' => $add->access(),
      ],
      '#cache' => ['tags' => ['commerce_domains_renewal_list']],
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
   * Implements hook_field_widget_complete_form_alter().
   *
   * Says which default renewal cost a domain without its own takes, on the
   * Costs tab and wherever else the field is edited.
   */
  #[Hook('field_widget_complete_form_alter')]
  public function renewalCostWidgetAlter(array &$field_widget_complete_form, FormStateInterface $form_state, array $context): void {
    $items = $context['items'] ?? NULL;
    if (!$items instanceof FieldItemListInterface || $items->getFieldDefinition()->getName() !== 'domain_renewal_cost' || !isset($field_widget_complete_form['widget'][0])) {
      return;
    }
    $product = $items->getEntity();
    if (!$product instanceof ProductInterface || !$product->hasField('domain') || $product->get('domain')->isEmpty()) {
      return;
    }
    $default = $this->costs->defaultRenewalCost((string) $product->get('domain')->value);
    $settings = Url::fromRoute('commerce_domains_owner.settings')->toString();
    $latest = $this->history->latestCost($product);
    if ($latest) {
      $note = $this->t('Leave empty to use what its latest renewal cost, @amount a year.', [
        '@amount' => $this->currencyFormatter->format($latest->getNumber(), $latest->getCurrencyCode()),
      ]);
    }
    elseif ($default) {
      $amount = $this->currencyFormatter->format($default->getNumber(), $default->getCurrencyCode());
      $note = $this->t('Leave empty to use the default for its extension, @amount a year (<a href=":settings">Domain portfolio settings</a>).', [
        '@amount' => $amount,
        ':settings' => $settings,
      ]);
    }
    else {
      $note = $this->t('No default renewal cost is set for its extension; set one in the <a href=":settings">Domain portfolio settings</a>.', [':settings' => $settings]);
    }
    $element = &$field_widget_complete_form['widget'][0];
    $element['#description'] = isset($element['#description']) && (string) $element['#description'] !== ''
      ? ['#markup' => $element['#description'] . ' ' . $note]
      : $note;
    $field_widget_complete_form['#cache']['tags'][] = 'config:commerce_domains_owner.settings';
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
      foreach (['domain_sold', 'domain_sold_via', 'domain_sale_commission', 'domain_lease_months'] as $field) {
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
   * Records the renewal a registrar sync shows. Takes a domain off sale once
   * its sale is entered by hand, so the marketplace syncs its save queued
   * remove its listings. Sales recorded from an order or a marketplace leave
   * that to them.
   */
  #[Hook('commerce_product_update')]
  public function productUpdate(ProductInterface $product): void {
    $this->history->recordFromSync($product, $this->costs->renewalCost($product), $this->time->getRequestTime());
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

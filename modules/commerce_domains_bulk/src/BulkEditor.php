<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk;

use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_domains_pricing\Rounding;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Changes a selection of domains, for the bulk actions' forms.
 *
 * Each edit works on the variations of the domains the current user may
 * change. Saving them updates their currency prices and marketplace
 * listings, as any save does.
 */
class BulkEditor {

  // Sets the prices from the pricing rules.
  public const PRICE_RULES = 'price_rules';

  // Multiplies the prices by a factor.
  public const REPRICE = 'reprice';

  // Sets the sale terms.
  public const SALE_TERMS = 'sale_terms';

  // Chooses the marketplaces that list the domains.
  public const MARKETPLACES = 'marketplaces';

  // Where the actions keep the selection for their forms.
  public const TEMPSTORE = 'commerce_domains_bulk';

  // Domains changed per batch request.
  public const BATCH_SIZE = 10;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected PriceRules $priceRules,
    protected Rounding $rounding,
    protected RounderInterface $rounder,
  ) {}

  /**
   * Whether an account may change a domain's variations.
   */
  public static function access(ProductInterface $product, ?AccountInterface $account = NULL): AccessResultInterface {
    $result = AccessResult::allowedIf($product->hasField('domain'))
      ->addCacheableDependency($product)
      ->andIf($product->access('update', $account, TRUE));
    foreach ($product->getVariations() as $variation) {
      $result = $result->andIf($variation->access('update', $account, TRUE));
    }
    return $result;
  }

  /**
   * Applies an edit to domains.
   *
   * @param string $edit
   *   One of the edit constants.
   * @param int[] $product_ids
   *   The domains' product IDs.
   * @param array $options
   *   The edit's options; see the methods for each edit.
   *
   * @return array<string, int>
   *   How many domains were changed, were left as they were, and were
   *   skipped as not domains or not the user's to change.
   */
  public function apply(string $edit, array $product_ids, array $options = []): array {
    $counts = ['changed' => 0, 'unchanged' => 0, 'skipped' => 0];
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($storage->loadMultiple($product_ids) as $product) {
      if (!self::access($product, $this->currentUser)->isAllowed()) {
        $counts['skipped']++;
        continue;
      }
      $changed = FALSE;
      foreach ($product->getVariations() as $variation) {
        $edited = match ($edit) {
          self::PRICE_RULES => $this->applyPriceRules($product, $variation),
          self::REPRICE => $this->reprice($variation, $options),
          self::SALE_TERMS => $this->setSaleTerms($variation, $options),
          self::MARKETPLACES => $this->setMarketplaces($variation, $options),
          default => throw new \InvalidArgumentException(sprintf('There is no %s edit.', $edit)),
        };
        if ($edited) {
          $variation->save();
          $changed = TRUE;
        }
      }
      $counts[$changed ? 'changed' : 'unchanged']++;
    }
    return $counts;
  }

  /**
   * Sets a variation's price from the pricing rules.
   *
   * A domain no rule matches keeps its price.
   */
  protected function applyPriceRules(ProductInterface $product, ProductVariationInterface $variation): bool {
    $atom_premium = $product->hasField('atom_premium') && (bool) $product->get('atom_premium')->value;
    $price = $this->priceRules->price($product->getTitle(), $atom_premium);
    if (!$price || self::same($variation->getPrice(), $price)) {
      return FALSE;
    }
    $variation->setPrice($price);
    return TRUE;
  }

  /**
   * Multiplies a variation's price by a factor.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param array $options
   *   The options:
   *   - factor: the number to multiply by, as a string.
   *   - round: whether to round with the rounding steps of the pricing
   *     settings; prices are rounded to the currency's decimals either way.
   *   - terms: whether to multiply the minimum offer and floor price too,
   *     where the domain sets them.
   */
  protected function reprice(ProductVariationInterface $variation, array $options): bool {
    $price = $variation->getPrice();
    if (!$price) {
      return FALSE;
    }
    $factor = (string) $options['factor'];
    $repriced = $price->multiply($factor);
    $repriced = empty($options['round']) ? $this->rounder->round($repriced) : $this->rounding->round($repriced);
    $changed = !self::same($price, $repriced);
    $variation->setPrice($repriced);

    foreach (empty($options['terms']) ? [] : ['sale_minimum_offer', 'sale_floor_price'] as $field) {
      if (!$variation->hasField($field) || $variation->get($field)->isEmpty()) {
        continue;
      }
      /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
      $item = $variation->get($field)->first();
      $amount = $item->toPrice();
      $scaled = $this->rounder->round($amount->multiply($factor));
      if (!self::same($amount, $scaled)) {
        $variation->set($field, $scaled);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Sets a variation's sale terms.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation; one without the sale terms trait is left alone.
   * @param array $options
   *   The terms to set:
   *   - buy_now, offers: TRUE or FALSE, or NULL to leave as they are.
   *   - minimum_offer, floor_price: an array with a mode of "keep", "clear"
   *     (worked out from the price) or "set", and for "set", the number and
   *     currency_code.
   *   - lease_months: an array with a mode of "keep", "clear" or "set", and
   *     for "set", the months.
   */
  protected function setSaleTerms(ProductVariationInterface $variation, array $options): bool {
    $values = [];
    foreach (['sale_buy_now' => 'buy_now', 'sale_offers' => 'offers'] as $field => $key) {
      if (isset($options[$key])) {
        $values[$field] = (bool) $options[$key];
      }
    }
    foreach (['sale_minimum_offer' => 'minimum_offer', 'sale_floor_price' => 'floor_price'] as $field => $key) {
      $mode = $options[$key]['mode'] ?? 'keep';
      if ($mode === 'clear') {
        $values[$field] = NULL;
      }
      elseif ($mode === 'set') {
        $values[$field] = new Price((string) $options[$key]['number'], (string) $options[$key]['currency_code']);
      }
    }
    $mode = $options['lease_months']['mode'] ?? 'keep';
    if ($mode !== 'keep') {
      $values['sale_lease_months'] = $mode === 'set' ? (int) $options['lease_months']['months'] : NULL;
    }

    $changed = FALSE;
    foreach ($values as $field => $value) {
      if (!$variation->hasField($field)) {
        continue;
      }
      $items = $variation->get($field);
      /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem|null $item */
      $item = $items->first();
      $same = match (TRUE) {
        $value === NULL => $items->isEmpty(),
        $value instanceof Price => self::same($item?->toPrice(), $value),
        // An empty flag counts as on, as the sale terms read it.
        is_bool($value) => ($items->isEmpty() || (bool) $items->value) === $value,
        default => !$items->isEmpty() && (int) $items->value === $value,
      };
      if (!$same) {
        $variation->set($field, $value);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Chooses the marketplaces that list a variation's domain.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation; one without the sale terms trait is left alone.
   * @param array $options
   *   The choice:
   *   - mode: "all" to list it on every marketplace, "none" to sell it on
   *     this site only, or "except" to keep it off some.
   *   - providers: for "except", the IDs of the providers to keep it off.
   */
  protected function setMarketplaces(ProductVariationInterface $variation, array $options): bool {
    if (!$variation->hasField('sale_marketplaces')) {
      return FALSE;
    }
    $changed = FALSE;
    $listed = $options['mode'] !== 'none';
    // An empty flag counts as on, as the sale terms read it.
    if (($variation->get('sale_marketplaces')->isEmpty() || (bool) $variation->get('sale_marketplaces')->value) !== $listed) {
      $variation->set('sale_marketplaces', $listed);
      $changed = TRUE;
    }

    if ($options['mode'] !== 'none' && $variation->hasField('sale_not_listed_on')) {
      $providers = $options['mode'] === 'except' ? array_values(array_unique($options['providers'] ?? [])) : [];
      $current = array_column($variation->get('sale_not_listed_on')->getValue(), 'target_id');
      sort($providers);
      sort($current);
      if ($current !== $providers) {
        $variation->set('sale_not_listed_on', $providers);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Whether two prices are the same amount in the same currency.
   */
  protected static function same(?Price $a, ?Price $b): bool {
    if (!$a || !$b) {
      return $a === $b;
    }
    return $a->getCurrencyCode() === $b->getCurrencyCode() && $a->equals($b);
  }

  /**
   * Batch operation: applies an edit to some domains.
   *
   * @param string $edit
   *   One of the edit constants.
   * @param int[] $product_ids
   *   The domains' product IDs.
   * @param array $options
   *   The edit's options.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function batchOperation(string $edit, array $product_ids, array $options, array|\ArrayAccess &$context): void {
    $counts = \Drupal::service(self::class)->apply($edit, $product_ids, $options);
    foreach ($counts as $key => $count) {
      $context['results'][$key] = ($context['results'][$key] ?? 0) + $count;
    }
  }

  /**
   * Batch finished callback: says what was done.
   */
  public static function batchFinished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('Not every domain could be changed. Check the log, and try again for the rest.'));
    }
    if ($count = $results['changed'] ?? 0) {
      $messenger->addStatus(new PluralTranslatableMarkup($count, 'Changed 1 domain.', 'Changed @count domains.'));
    }
    if ($count = $results['unchanged'] ?? 0) {
      $messenger->addStatus(new PluralTranslatableMarkup($count, '1 domain was already as asked, or had nothing to change.', '@count domains were already as asked, or had nothing to change.'));
    }
    if ($count = $results['skipped'] ?? 0) {
      $messenger->addWarning(new PluralTranslatableMarkup($count, '1 domain was skipped: you may not change it.', '@count domains were skipped: you may not change them.'));
    }
  }

}

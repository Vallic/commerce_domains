<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_pricing;

use Drupal\commerce_exchanger\Exception\ExchangeRatesDataMismatchException;
use Drupal\commerce_exchanger\ExchangerCalculatorInterface;
use Drupal\commerce_pricelist\Entity\PriceListInterface;
use Drupal\commerce_pricelist\Entity\PriceListItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Keeps a domain's prices in other currencies: converted, rounded, stored.
 *
 * On each save of a domain variation, its price is converted into every
 * currency the domains are priced in, its own included, at Commerce
 * Exchanger's rates, and rounded. The prices go into price fields named
 * after the currency, such as field_price_usd, where the variation has them,
 * and into a price list (Commerce Pricelist) that marketplaces can name.
 * Currencies without a rate keep what they have.
 *
 * Syncing saves, such as migrations, keep the stored prices: nothing is
 * converted, and the price list follows the price fields.
 */
class CurrencyPrices {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Rounding $rounding,
    protected ?ExchangerCalculatorInterface $exchanger = NULL,
  ) {}

  /**
   * Returns the currencies domains are priced in.
   *
   * @return string[]
   *   The chosen currencies that are enabled, or every enabled currency
   *   when none is chosen.
   */
  public function currencies(): array {
    $enabled = array_values($this->entityTypeManager->getStorage('commerce_currency')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->sort('currencyCode')
      ->execute());
    $chosen = $this->config()->get('currencies') ?: [];
    return $chosen ? array_values(array_intersect($chosen, $enabled)) : $enabled;
  }

  /**
   * Works out a variation's price in each currency: converted, then rounded.
   *
   * @return array<string, \Drupal\commerce_price\Price>
   *   Prices keyed by currency code. A currency without an exchange rate,
   *   or every other currency without Commerce Exchanger, is left out.
   */
  public function calculate(ProductVariationInterface $variation): array {
    $price = $variation->getPrice();
    if (!$price) {
      return [];
    }
    $prices = [];
    foreach ($this->currencies() as $currency) {
      if ($currency === $price->getCurrencyCode()) {
        $converted = $price;
      }
      elseif (!$this->exchanger) {
        continue;
      }
      else {
        try {
          $converted = $this->exchanger->priceConversion($price, $currency);
        }
        catch (ExchangeRatesDataMismatchException) {
          continue;
        }
      }
      $prices[$currency] = $this->rounding->round($converted);
    }
    return $prices;
  }

  /**
   * Writes the prices into the variation's price fields, before it is saved.
   *
   * A syncing save keeps the fields as it brings them.
   */
  public function updateFields(ProductVariationInterface $variation): void {
    if ($variation->isSyncing() || (string) $this->config()->get('field_prefix') === '') {
      return;
    }
    foreach ($this->calculate($variation) as $currency => $price) {
      if ($field = $this->field($variation, $currency)) {
        $variation->set($field, $price);
      }
    }
  }

  /**
   * Brings the variation's items in the price list in line, once it is saved.
   *
   * Each currency gets one enabled item at a quantity of one; items in
   * currencies the domains aren't priced in are removed.
   */
  public function updatePriceList(ProductVariationInterface $variation): void {
    $list = $this->priceList();
    if (!$list || $variation->isNew() || !($prices = $this->listPrices($variation))) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_pricelist_item');
    $items = [];
    /** @var \Drupal\commerce_pricelist\Entity\PriceListItemInterface $item */
    foreach ($storage->loadByProperties(['price_list_id' => $list->id(), 'purchasable_entity' => $variation->id()]) as $item) {
      $currency = $item->getPrice()?->getCurrencyCode() ?? '';
      $items[$currency] ??= [];
      // The item at a quantity of one first: the one to keep.
      if ((float) $item->getQuantity() === 1.0) {
        array_unshift($items[$currency], $item);
      }
      else {
        $items[$currency][] = $item;
      }
    }
    $currencies = $this->currencies();
    $delete = [];
    foreach ($items as $currency => $current) {
      if (!in_array($currency, $currencies, TRUE)) {
        array_push($delete, ...$current);
      }
    }

    $save = [];
    foreach ($prices as $currency => $price) {
      $current = $items[$currency] ?? [];
      $item = $price ? array_shift($current) : NULL;
      array_push($delete, ...$current);
      if (!$price) {
        continue;
      }
      $item ??= $storage->create([
        'type' => 'commerce_product_variation',
        'price_list_id' => $list->id(),
        'purchasable_entity' => $variation->id(),
      ]);
      assert($item instanceof PriceListItemInterface);
      if ($item->isNew() || !$item->getPrice()?->equals($price) || !$item->isEnabled() || (float) $item->getQuantity() !== 1.0) {
        $save[] = $item->setPrice($price)->setQuantity('1')->setEnabled(TRUE);
      }
    }
    // Deleted first: the list allows one item per currency and quantity.
    if ($delete) {
      $storage->delete($delete);
    }
    foreach ($save as $item) {
      $item->save();
    }
  }

  /**
   * Returns the price list that gets the prices, if there is one.
   */
  public function priceList(): ?PriceListInterface {
    $uuid = $this->config()->get('price_list');
    if (!is_string($uuid) || $uuid === '' || !$this->entityTypeManager->hasDefinition('commerce_pricelist')) {
      return NULL;
    }
    $lists = $this->entityTypeManager->getStorage('commerce_pricelist')->loadByProperties(['uuid' => $uuid]);
    $list = reset($lists);
    return $list instanceof PriceListInterface ? $list : NULL;
  }

  /**
   * Returns the prices the price list gets, by currency.
   *
   * A currency with a price field takes the field's price: the one this save
   * wrote, or the one a syncing save brought. A currency without one takes
   * the price worked out, except on a syncing save.
   *
   * @return array<string, \Drupal\commerce_price\Price|null>
   *   Prices keyed by currency code, NULL where the field is empty. Currencies
   *   whose price isn't known are left out.
   */
  protected function listPrices(ProductVariationInterface $variation): array {
    $calculated = $variation->isSyncing() ? [] : $this->calculate($variation);
    $prices = [];
    foreach ($this->currencies() as $currency) {
      if ($field = $this->field($variation, $currency)) {
        /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem|null $item */
        $item = $variation->get($field)->first();
        $price = $item?->toPrice();
        $prices[$currency] = $price && $price->getCurrencyCode() === $currency ? $price : NULL;
      }
      elseif (isset($calculated[$currency])) {
        $prices[$currency] = $calculated[$currency];
      }
    }
    return $prices;
  }

  /**
   * Returns the variation's price field for a currency, if it has one.
   */
  protected function field(ProductVariationInterface $variation, string $currency): ?string {
    $prefix = (string) $this->config()->get('field_prefix');
    $name = $prefix . strtolower($currency);
    if ($prefix === '' || !$variation->hasField($name) || $variation->getFieldDefinition($name)?->getType() !== 'commerce_price') {
      return NULL;
    }
    return $name;
  }

  /**
   * Returns the pricing settings.
   */
  protected function config(): ImmutableConfig {
    return $this->configFactory->get(PriceRules::CONFIG);
  }

}

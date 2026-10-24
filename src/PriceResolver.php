<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_exchanger\Exception\ExchangeRatesDataMismatchException;
use Drupal\commerce_exchanger\ExchangerCalculatorInterface;
use Drupal\commerce_pricelist\Entity\PriceListInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Works out a variation's price in each of the store's currencies.
 *
 * In order: the items of a price list when the caller names one (Commerce
 * Pricelist), the variation's own price, then the variation price converted
 * at Commerce Exchanger's rates. Both modules are optional; without them only
 * the variation's own currency has a price.
 */
class PriceResolver {

  /**
   * Price lists by UUID; NULL when missing or outside their dates.
   *
   * @var array<string, \Drupal\commerce_pricelist\Entity\PriceListInterface|null>
   */
  protected array $priceLists = [];

  /**
   * The codes of the enabled currencies, once loaded.
   *
   * @var string[]|null
   */
  protected ?array $currencies = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected ?ExchangerCalculatorInterface $exchanger = NULL,
  ) {}

  /**
   * Returns the prices of a variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param string|null $price_list
   *   The UUID of a price list whose items win for their currency. The list
   *   needn't apply to a store, so a disabled list keeps these prices off
   *   the storefront; its dates still count.
   *
   * @return array<string, float>
   *   Amounts keyed by currency code.
   */
  public function prices(ProductVariationInterface $variation, ?string $price_list = NULL): array {
    $price = $variation->getPrice();
    if (!$price) {
      return [];
    }
    $prices = [];
    if ($list = $this->priceList($price_list)) {
      $prices = $this->listPrices($list, $variation);
    }
    $prices += [$price->getCurrencyCode() => (float) $price->getNumber()];

    if ($this->exchanger) {
      foreach (array_diff($this->currencies(), array_keys($prices)) as $currency) {
        try {
          $prices[$currency] = (float) $this->exchanger->priceConversion($price, $currency)->getNumber();
        }
        catch (ExchangeRatesDataMismatchException) {
          // No rate between the two currencies, so no price in this one.
        }
      }
    }
    return $prices;
  }

  /**
   * Returns a price list's prices for a variation, at a quantity of one.
   *
   * @return array<string, float>
   *   Amounts keyed by currency code.
   */
  protected function listPrices(PriceListInterface $list, ProductVariationInterface $variation): array {
    /** @var \Drupal\commerce_pricelist\Entity\PriceListItemInterface[] $items */
    $items = $this->entityTypeManager->getStorage('commerce_pricelist_item')->loadByProperties([
      'price_list_id' => $list->id(),
      'purchasable_entity' => $variation->id(),
      'status' => TRUE,
    ]);
    // Tiers for larger quantities don't apply to a single domain.
    usort($items, fn($a, $b) => (float) $a->getQuantity() <=> (float) $b->getQuantity());
    $prices = [];
    foreach ($items as $item) {
      $price = $item->getPrice();
      if ($price && (float) $item->getQuantity() <= 1) {
        $prices += [$price->getCurrencyCode() => (float) $price->getNumber()];
      }
    }
    return $prices;
  }

  /**
   * Loads a price list, if it is in its dates.
   */
  protected function priceList(?string $uuid): ?PriceListInterface {
    if ($uuid === NULL || $uuid === '' || !$this->entityTypeManager->hasDefinition('commerce_pricelist')) {
      return NULL;
    }
    if (!array_key_exists($uuid, $this->priceLists)) {
      $lists = $this->entityTypeManager->getStorage('commerce_pricelist')->loadByProperties(['uuid' => $uuid]);
      $list = reset($lists);
      $active = FALSE;
      if ($list instanceof PriceListInterface) {
        // Dates are kept in the store's time, as Commerce Pricelist reads them.
        $stores = $list->getStores();
        $timezone = $stores ? reset($stores)->getTimezone() : date_default_timezone_get();
        $now = $this->time->getCurrentTime();
        $active = ($list->getStartDate($timezone)?->getTimestamp() ?? 0) <= $now
          && ($list->getEndDate($timezone)?->getTimestamp() ?? PHP_INT_MAX) > $now;
      }
      $this->priceLists[$uuid] = $active ? $list : NULL;
    }
    return $this->priceLists[$uuid];
  }

  /**
   * Returns the codes of the enabled currencies.
   *
   * @return string[]
   *   Currency codes.
   */
  protected function currencies(): array {
    return $this->currencies ??= array_values($this->entityTypeManager->getStorage('commerce_currency')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->execute());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_exchanger\Exception\ExchangeRatesDataMismatchException;
use Drupal\commerce_exchanger\ExchangerCalculatorInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Works out what a sold domain earned.
 *
 * Profit is the sale price minus the purchase price minus the renewals paid.
 * Renewals paid is the yearly renewal cost times the renewals while the
 * domain was held: one on each anniversary of its expiry date (or, without
 * one, of its purchase date) after the purchase, up to the sale. Without a
 * purchase date no renewals count, and without a renewal cost they cost
 * nothing. Costs in another currency than the sale are converted at
 * Commerce Exchanger's current rates when it is installed.
 */
class Costs {

  public function __construct(
    // Commerce Exchanger is optional.
    protected ?ExchangerCalculatorInterface $exchanger = NULL,
  ) {}

  /**
   * Returns what a domain earned.
   */
  public function profit(ProductInterface $product): Profit {
    $sale = self::price($product, 'domain_sale_price');
    $purchase = self::price($product, 'domain_purchase_price');
    $renewal = self::price($product, 'domain_renewal_cost');
    $purchased = self::time($product, 'domain_purchase_date');
    $sold = self::time($product, 'domain_sold');
    $renewals = $purchased !== NULL && $sold !== NULL ? self::renewals($purchased, $sold, self::time($product, 'domain_expires')) : 0;
    $renewals_paid = $renewal?->multiply((string) $renewals);

    if (!$sale || !$purchase) {
      $problem = $sale ? new TranslatableMarkup('No purchase price.') : new TranslatableMarkup('No sale price.');
      return new Profit($sale, $purchase, $renewals, $renewals_paid, NULL, $problem);
    }

    $currency = $sale->getCurrencyCode();
    $cost = $this->convert($purchase, $currency);
    if ($cost && $renewals_paid) {
      $paid = $this->convert($renewals_paid, $currency);
      $cost = $paid ? $cost->add($paid) : NULL;
    }
    if (!$cost) {
      $problem = new TranslatableMarkup('The costs are in another currency than the sale, and there is no exchange rate for it.');
      return new Profit($sale, $purchase, $renewals, $renewals_paid, NULL, $problem);
    }
    return new Profit($sale, $purchase, $renewals, $renewals_paid, $sale->subtract($cost));
  }

  /**
   * Counts the renewals paid while a domain was held.
   *
   * @param int $purchased
   *   When it was bought, as a Unix time.
   * @param int $sold
   *   When it sold.
   * @param int|null $expires
   *   When its registration runs out, if known: registrations renew on its
   *   anniversaries. Without it they renew on the purchase date's.
   */
  public static function renewals(int $purchased, int $sold, ?int $expires = NULL): int {
    if ($sold <= $purchased) {
      return 0;
    }
    $anchor = new \DateTimeImmutable('@' . ($expires ?? $purchased));
    // Each anniversary counted from the anchor itself, so a 29 February
    // doesn't drift into March.
    $anniversary = fn(int $years) => $anchor->modify(sprintf('%+d years', $years))->getTimestamp();
    $years = (int) gmdate('Y', $purchased) - (int) $anchor->format('Y') - 1;
    while ($anniversary($years) <= $purchased) {
      $years++;
    }
    $count = 0;
    while ($anniversary($years + $count) <= $sold) {
      $count++;
    }
    return $count;
  }

  /**
   * Returns a price field's value.
   */
  public static function price(ProductInterface $product, string $field): ?Price {
    if (!$product->hasField($field) || $product->get($field)->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
    $item = $product->get($field)->first();
    return $item->toPrice();
  }

  /**
   * Returns a timestamp field's value.
   */
  public static function time(ProductInterface $product, string $field): ?int {
    if (!$product->hasField($field) || $product->get($field)->isEmpty()) {
      return NULL;
    }
    return (int) $product->get($field)->value;
  }

  /**
   * Converts an amount into a currency, when there is a rate.
   */
  public function convert(Price $price, string $currency): ?Price {
    if ($price->getCurrencyCode() === $currency) {
      return $price;
    }
    if (!$this->exchanger) {
      return NULL;
    }
    try {
      return $this->exchanger->priceConversion($price, $currency);
    }
    catch (ExchangeRatesDataMismatchException) {
      return NULL;
    }
  }

}

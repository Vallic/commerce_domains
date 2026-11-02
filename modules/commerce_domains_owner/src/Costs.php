<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_exchanger\Exception\ExchangeRatesDataMismatchException;
use Drupal\commerce_exchanger\ExchangerCalculatorInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Works out what a sold domain earned.
 *
 * Profit is what the seller received (the sale price less the marketplace's
 * commission) minus the purchase price minus the renewals paid.
 *
 * Renewals paid are the renewals recorded while the domain was held, at what
 * they cost (see RenewalHistory), and an estimate for the years they don't
 * cover. A domain renews on each anniversary of its expiry date (or, without
 * one, of its purchase date) after the purchase, up to the sale; those not
 * covered by a record count at the yearly cost, the oldest first, since
 * records usually start when a domain reached the registrar they came from.
 * Without a purchase date no anniversaries count.
 *
 * The yearly cost is the domain's renewal cost, else what its latest recorded
 * renewal cost a year, else the default for its extension (Domain portfolio
 * settings); without any, an estimated renewal costs nothing. Costs in another
 * currency than the sale are converted at Commerce Exchanger's current rates
 * when it is installed.
 */
class Costs {

  public function __construct(
    // Commerce Exchanger is optional.
    protected ?ExchangerCalculatorInterface $exchanger = NULL,
    protected ?ConfigFactoryInterface $configFactory = NULL,
    protected ?RenewalHistory $history = NULL,
  ) {}

  /**
   * Returns what a domain costs to renew for a year.
   *
   * Its own renewal cost, else what its latest recorded renewal cost a year,
   * else the default for its extension.
   */
  public function renewalCost(ProductInterface $product): ?Price {
    $cost = self::price($product, 'domain_renewal_cost') ?? $this->history?->latestCost($product);
    if ($cost || !$product->hasField('domain')) {
      return $cost;
    }
    return $this->defaultRenewalCost((string) $product->get('domain')->value);
  }

  /**
   * Whether a domain's yearly cost is its extension's default.
   */
  public function renewsAtDefault(ProductInterface $product): bool {
    return self::price($product, 'domain_renewal_cost') === NULL
      && $this->history?->latestCost($product) === NULL
      && $this->renewalCost($product) !== NULL;
  }

  /**
   * Returns the renewals paid while a domain was held, recorded or estimated.
   *
   * A renewal recorded before the purchase was someone else's, and one after
   * the end isn't this holding's. The day of the purchase counts: an import
   * knows only the date.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The domain.
   * @param int $until
   *   When it sold, or now for a domain still held.
   *
   * @return array<int, array{time: int, years: int, amount: \Drupal\commerce_price\Price|null, estimated: bool}>
   *   Oldest first. The amount is NULL when no cost is known.
   */
  public function renewalPayments(ProductInterface $product, int $until): array {
    $purchased = self::time($product, 'domain_purchase_date');
    $yearly = $this->renewalCost($product);
    $payments = [];
    $covered = 0;
    foreach ($this->history?->forProduct($product) ?? [] as $renewal) {
      $time = $renewal->getPaid();
      if ($time > $until || ($purchased !== NULL && $time < $purchased - 86400)) {
        continue;
      }
      $years = $renewal->getYears();
      $amount = $renewal->getAmount();
      $covered += $years;
      $payments[] = [
        'time' => $time,
        'years' => $years,
        'amount' => $amount ?? $yearly?->multiply((string) $years),
        'estimated' => !$amount || $renewal->isEstimated(),
      ];
    }
    if ($purchased !== NULL) {
      $dates = self::renewalDates($purchased, $until, self::time($product, 'domain_expires'));
      foreach (array_slice($dates, 0, max(0, count($dates) - $covered)) as $date) {
        $payments[] = ['time' => $date, 'years' => 1, 'amount' => $yearly, 'estimated' => TRUE];
      }
    }
    usort($payments, fn(array $a, array $b) => $a['time'] <=> $b['time']);
    return $payments;
  }

  /**
   * Adds up renewal payments in one amount.
   *
   * @param array $payments
   *   Payments from renewalPayments().
   * @param string|null $currency
   *   The currency to convert to when they are in several.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The sum; NULL with no amounts, or without a rate between them.
   */
  public function renewalsTotal(array $payments, ?string $currency = NULL): ?Price {
    $amounts = array_values(array_filter(array_column($payments, 'amount')));
    if (!$amounts) {
      return NULL;
    }
    $currencies = array_unique(array_map(fn(Price $price) => $price->getCurrencyCode(), $amounts));
    $target = count($currencies) === 1 ? reset($currencies) : ($currency ?? reset($currencies));
    $total = new Price('0', $target);
    foreach ($amounts as $amount) {
      $converted = $this->convert($amount, $target);
      if (!$converted) {
        return NULL;
      }
      $total = $total->add($converted);
    }
    return $total;
  }

  /**
   * Returns the default yearly renewal cost of a domain's extension.
   *
   * The longest extension set that the domain ends with: co.uk before uk.
   */
  public function defaultRenewalCost(string $name): ?Price {
    $name = strtolower($name);
    $best = NULL;
    foreach ((array) $this->configFactory?->get('commerce_domains_owner.settings')->get('renewal_costs') as $cost) {
      $tld = (string) ($cost['tld'] ?? '');
      if ($tld !== '' && str_ends_with($name, '.' . $tld) && strlen($tld) > strlen((string) ($best['tld'] ?? ''))) {
        $best = $cost;
      }
    }
    return $best ? new Price((string) $best['number'], (string) $best['currency_code']) : NULL;
  }

  /**
   * Returns what a domain earned.
   */
  public function profit(ProductInterface $product): Profit {
    $sale = self::price($product, 'domain_sale_price');
    $purchase = self::price($product, 'domain_purchase_price');
    $renewal = $this->renewalCost($product);
    $sold = self::time($product, 'domain_sold');
    $payments = $sold !== NULL ? $this->renewalPayments($product, $sold) : [];
    $renewals = array_sum(array_column($payments, 'years'));
    $renewals_paid = $this->renewalsTotal($payments, $sale?->getCurrencyCode());
    // Amounts in currencies with no rate between them can't be added up.
    $unknown = !$renewals_paid && array_filter(array_column($payments, 'amount'));
    $renewals_paid ??= $unknown ? NULL : $renewal?->multiply('0');

    $percent = $product->hasField('domain_sale_commission') ? (float) $product->get('domain_sale_commission')->value : 0.0;
    $commission = $sale && $percent > 0 ? new Price((string) round((float) $sale->getNumber() * $percent / 100, 2), $sale->getCurrencyCode()) : NULL;
    if (!$sale || !$purchase) {
      $problem = $sale ? new TranslatableMarkup('No purchase price.') : new TranslatableMarkup('No sale price.');
      return new Profit($sale, $purchase, $renewals, $renewals_paid, NULL, $problem, $commission);
    }
    // What the seller kept.
    $received = $commission ? $sale->subtract($commission) : $sale;

    $currency = $sale->getCurrencyCode();
    $cost = $this->convert($purchase, $currency);
    if ($cost && $renewals_paid) {
      $paid = $this->convert($renewals_paid, $currency);
      $cost = $paid ? $cost->add($paid) : NULL;
    }
    elseif ($unknown) {
      $cost = NULL;
    }
    if (!$cost) {
      $problem = new TranslatableMarkup('The costs are in another currency than the sale, and there is no exchange rate for it.');
      return new Profit($sale, $purchase, $renewals, $renewals_paid, NULL, $problem, $commission);
    }
    return new Profit($sale, $purchase, $renewals, $renewals_paid, $received->subtract($cost), NULL, $commission);
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
    return count(self::renewalDates($purchased, $sold, $expires));
  }

  /**
   * Returns when the renewals paid while a domain was held fell due.
   *
   * @param int $purchased
   *   When it was bought, as a Unix time.
   * @param int $until
   *   When it sold, or now for a domain still held.
   * @param int|null $expires
   *   When its registration runs out, if known: registrations renew on its
   *   anniversaries. Without it they renew on the purchase date's.
   *
   * @return int[]
   *   Unix times, oldest first.
   */
  public static function renewalDates(int $purchased, int $until, ?int $expires = NULL): array {
    if ($until <= $purchased) {
      return [];
    }
    $anchor = new \DateTimeImmutable('@' . ($expires ?? $purchased));
    // Each anniversary counted from the anchor itself, so a 29 February
    // doesn't drift into March.
    $anniversary = fn(int $years) => $anchor->modify(sprintf('%+d years', $years))->getTimestamp();
    $years = (int) gmdate('Y', $purchased) - (int) $anchor->format('Y') - 1;
    while ($anniversary($years) <= $purchased) {
      $years++;
    }
    $dates = [];
    while (($date = $anniversary($years + count($dates))) <= $until) {
      $dates[] = $date;
    }
    return $dates;
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

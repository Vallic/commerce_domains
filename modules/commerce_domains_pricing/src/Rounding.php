<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_pricing;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Rounds prices with the steps from the pricing settings.
 *
 * A step covers the amounts up to its limit, and rounds them up, down or to
 * the nearest multiple of an amount, then subtracts a fixed amount: up to a
 * multiple of 100, less 1, turns 1,234 into 1,299. An amount above every
 * limit is left as it is. The same steps apply in every currency.
 */
class Rounding {

  // Decimals kept while working, as Commerce stores prices.
  protected const SCALE = 6;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected RounderInterface $rounder,
  ) {}

  /**
   * Rounds a price with the steps from the settings.
   *
   * The result is rounded to the currency's decimals too.
   */
  public function round(Price $price): Price {
    $config = $this->configFactory->get(PriceRules::CONFIG);
    $number = self::roundNumber($price->getNumber(), $config->get('rounding.steps') ?? [], (bool) $config->get('rounding.whole_units'));
    return $this->rounder->round(new Price($number, $price->getCurrencyCode()));
  }

  /**
   * Rounds an amount with the given steps.
   *
   * @param string $number
   *   The amount.
   * @param array $steps
   *   Steps as the settings keep them: up_to (NULL for no limit), method (up,
   *   down or nearest), multiple, subtract and max (NULL for no limit).
   * @param bool $whole_units
   *   Drop the cents before choosing the step and rounding.
   *
   * @return string
   *   The rounded amount; never below zero.
   */
  public static function roundNumber(string $number, array $steps, bool $whole_units = FALSE): string {
    if ($whole_units) {
      // Towards zero, as a cast to an integer does.
      $number = bcadd($number, '0', 0);
    }
    $step = self::step($number, $steps);
    if ($step === NULL || (float) ($step['multiple'] ?? 0) <= 0) {
      return Calculator::trim(bcadd($number, '0', self::SCALE));
    }

    $multiple = self::decimal($step['multiple']);
    $quotient = bcdiv($number, $multiple, 12);
    $quotient = match ($step['method'] ?? 'up') {
      'down' => self::floor($quotient),
      'nearest' => Calculator::round($quotient),
      default => self::ceil($quotient),
    };
    $rounded = bcsub(bcmul($quotient, $multiple, self::SCALE), self::decimal($step['subtract'] ?? 0), self::SCALE);

    if (isset($step['max']) && bccomp($rounded, self::decimal($step['max']), self::SCALE) > 0) {
      $rounded = self::decimal($step['max']);
    }
    // Subtracting from nothing would give a negative price.
    if (bccomp($rounded, '0', self::SCALE) < 0) {
      $rounded = '0';
    }
    return Calculator::trim(bcadd($rounded, '0', self::SCALE));
  }

  /**
   * Returns the step for an amount: the one with the lowest limit above it.
   *
   * @return array|null
   *   The step, or NULL when the amount is above every limit.
   */
  protected static function step(string $number, array $steps): ?array {
    // Steps without a limit come last.
    $order = fn(array $step): array => [!isset($step['up_to']), (float) ($step['up_to'] ?? 0)];
    usort($steps, fn(array $a, array $b) => $order($a) <=> $order($b));
    foreach ($steps as $step) {
      if (!isset($step['up_to']) || bccomp($number, self::decimal($step['up_to']), self::SCALE) <= 0) {
        return $step;
      }
    }
    return NULL;
  }

  /**
   * Returns the next whole number up; Calculator::ceil() adds one to those.
   */
  protected static function ceil(string $number): string {
    $whole = bcadd($number, '0', 0);
    return bccomp($number, $whole, 12) > 0 ? bcadd($whole, '1', 0) : $whole;
  }

  /**
   * Returns the next whole number down.
   */
  protected static function floor(string $number): string {
    $whole = bcadd($number, '0', 0);
    return bccomp($number, $whole, 12) < 0 ? bcsub($whole, '1', 0) : $whole;
  }

  /**
   * Turns a number from the settings into one bcmath takes: no exponents.
   */
  protected static function decimal(int|float|string $value): string {
    if (is_string($value)) {
      return is_numeric($value) ? $value : '0';
    }
    return Calculator::trim(number_format((float) $value, self::SCALE, '.', ''));
  }

}

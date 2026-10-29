<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_pricing\Kernel;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;

/**
 * Helpers for pricing tests, and NameAcre's rounding to compare with.
 */
trait PricingTestTrait {

  /**
   * NameAcre's rule in steps: up to 10,000 end in 99, then to the next 100.
   *
   * @var array
   */
  protected static array $nameAcreSteps = [
    ['up_to' => 10000.0, 'method' => 'up', 'multiple' => 100.0, 'subtract' => 1.0, 'max' => 9999.0],
    ['up_to' => NULL, 'method' => 'nearest', 'multiple' => 100.0, 'subtract' => 0.0, 'max' => NULL],
  ];

  /**
   * NameAcre's ProductVariationSubscriber::getRoundNumber(), as written.
   *
   * It gets the converted price cast to an integer.
   */
  protected static function nameAcreRound(int $price): int {
    $threshold = 10000;
    if ($price <= $threshold) {
      $rounded_price = ceil($price / 100) * 100 - 1;
      if ($rounded_price > $threshold) {
        return 9999;
      }
    }
    else {
      $rounded_price = round($price, -2);
    }
    return (int) $rounded_price;
  }

  /**
   * Sets the rounding to NameAcre's.
   */
  protected function setNameAcreRounding(): void {
    $this->config('commerce_domains_pricing.settings')
      ->set('rounding', ['whole_units' => TRUE, 'steps' => self::$nameAcreSteps])
      ->save();
  }

  /**
   * Asserts a price, whatever decimals its number is written with.
   */
  protected function assertPrice(string $number, string $currency, ?Price $price, string $message = ''): void {
    $this->assertNotNull($price, $message);
    $this->assertSame([$currency, 0], [$price->getCurrencyCode(), Calculator::compare($price->getNumber(), $number)], $message ?: "$number $currency, not {$price->getNumber()} {$price->getCurrencyCode()}");
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_pricing\Kernel;

use Drupal\commerce_exchanger\Exception\ExchangeRatesDataMismatchException;
use Drupal\commerce_exchanger\ExchangerCalculatorInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Core\State\StateInterface;

/**
 * Converts prices at rates kept in State, as Commerce Exchanger would.
 */
final class TestExchanger implements ExchangerCalculatorInterface {

  // Where the rates are kept, by source and target currency.
  public const STATE = 'commerce_domains_pricing_test.rates';

  // The rates when State has none.
  public const RATES = [
    'USD' => ['EUR' => '0.9', 'GBP' => '0.8'],
  ];

  public function __construct(protected StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function priceConversion(Price $price, string $target_currency) {
    if ($price->getCurrencyCode() === $target_currency) {
      return $price;
    }
    $rate = $this->getExchangeRates()[$price->getCurrencyCode()][$target_currency] ?? NULL;
    if (!$rate) {
      throw new ExchangeRatesDataMismatchException('No rate.');
    }
    // Rounded to cents, as Commerce Exchanger's rounder does.
    return new Price(Calculator::round(Calculator::multiply($price->getNumber(), $rate), 2), $target_currency);
  }

  /**
   * {@inheritdoc}
   */
  public function getExchangeRates() {
    return $this->state->get(self::STATE, self::RATES);
  }

  /**
   * {@inheritdoc}
   */
  public function getExchangerId() {
    return 'test';
  }

}

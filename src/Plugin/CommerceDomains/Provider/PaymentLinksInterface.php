<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_price\Price;

/**
 * A provider that makes payment links for a domain.
 *
 * The buyer pays there, at an agreed price, and the provider hands the
 * domain over.
 */
interface PaymentLinksInterface extends ProviderPluginInterface {

  /**
   * Returns the currencies a link can be made in.
   *
   * @return string[]
   *   Currency codes.
   */
  public function paymentLinkCurrencies(): array;

  /**
   * Returns the least amount of one payment, e.g. each lease installment.
   */
  public function paymentLinkMinimum(): float;

  /**
   * Makes a link for a buyer to pay for a domain.
   *
   * @param string $name
   *   The domain, normalized.
   * @param \Drupal\commerce_price\Price $price
   *   The agreed price, in one of paymentLinkCurrencies().
   * @param string $buyer_email
   *   The buyer's email address.
   * @param array $options
   *   Options:
   *   - buyer_fee: the buyer's share of the provider's fee, in percent.
   *   - lease_months: for a lease to own, the number of monthly payments.
   *   - down_payment: for a lease, the share of the price paid first, in
   *     percent.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function createPaymentLink(string $name, Price $price, string $buyer_email, array $options = []): PaymentLink;

  /**
   * Reads a link's state.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function fetchPaymentLink(string $id): PaymentLink;

}

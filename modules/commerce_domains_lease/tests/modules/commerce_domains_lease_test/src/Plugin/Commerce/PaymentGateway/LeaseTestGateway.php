<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease_test\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\PluginForm\OnsitePaymentMethodAddForm;
use Drupal\commerce_payment_example\Plugin\Commerce\PaymentGateway\Onsite;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Takes money, or fails to report it, as the state says.
 *
 * In state, commerce_domains_lease_test.outcome is one of:
 * - pay: takes the money and records it.
 * - lost: takes the money, then reports an error, as a timeout does.
 * - late_error: takes and records the money, then reports an error.
 * - refused: refuses the request, so no money moves.
 *
 * commerce_domains_lease_test.taken counts each time money was taken.
 */
#[CommercePaymentGateway(
  id: 'lease_test_gateway',
  label: new TranslatableMarkup('Lease test gateway'),
  display_label: new TranslatableMarkup('Lease test gateway'),
  forms: ['add-payment-method' => OnsitePaymentMethodAddForm::class],
  payment_method_types: ['credit_card'],
)]
class LeaseTestGateway extends Onsite {

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    $state = \Drupal::state();
    $outcome = $state->get('commerce_domains_lease_test.outcome', 'pay');
    if ($outcome === 'refused') {
      throw new InvalidRequestException('Refused.');
    }
    $state->set('commerce_domains_lease_test.taken', $state->get('commerce_domains_lease_test.taken', 0) + 1);
    if ($outcome === 'lost') {
      throw new InvalidResponseException('Network communication failed.');
    }
    parent::createPayment($payment, $capture);
    if ($outcome === 'late_error') {
      throw new InvalidResponseException('The update after the charge failed.');
    }
  }

}

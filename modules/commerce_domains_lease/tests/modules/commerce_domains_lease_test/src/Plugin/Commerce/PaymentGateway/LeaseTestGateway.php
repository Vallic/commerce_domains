<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease_test\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment_example\Plugin\Commerce\PaymentGateway\Onsite;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
  payment_method_types: ['credit_card'],
)]
class LeaseTestGateway extends Onsite {

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    $outcome = $this->state->get('commerce_domains_lease_test.outcome', 'pay');
    if ($outcome === 'refused') {
      throw new InvalidRequestException('Refused.');
    }
    $this->state->set('commerce_domains_lease_test.taken', $this->state->get('commerce_domains_lease_test.taken', 0) + 1);
    if ($outcome === 'lost') {
      throw new InvalidResponseException('Network communication failed.');
    }
    parent::createPayment($payment, $capture);
    if ($outcome === 'late_error') {
      throw new InvalidResponseException('The update after the charge failed.');
    }
  }

}

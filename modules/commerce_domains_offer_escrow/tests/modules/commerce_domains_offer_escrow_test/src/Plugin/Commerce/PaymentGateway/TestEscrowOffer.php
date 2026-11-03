<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer_escrow_test\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface;
use Drupal\commerce_escrow\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * An Escrow Offer gateway that answers as Escrow.com would.
 *
 * Set the State key commerce_domains_offer_escrow_test.fail to refuse.
 */
#[CommercePaymentGateway(
  id: 'test_escrow_offer',
  label: new TranslatableMarkup('Test Escrow Offer'),
  display_label: new TranslatableMarkup('Test Escrow Offer'),
  forms: [
    'offsite-payment' => PaymentOffsiteForm::class,
  ],
  payment_type: 'escrow_payment',
)]
class TestEscrowOffer extends OffsitePaymentGatewayBase implements EscrowGatewayInterface {

  /**
   * The state, which says whether to refuse.
   *
   * @var \Drupal\Core\State\StateInterface
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
  public function initiateEscrow(OrderInterface $order): array {
    if ($this->state->get('commerce_domains_offer_escrow_test.fail')) {
      throw new \RuntimeException('Refused.');
    }
    return [
      'make_offer_page' => 'https://escrow.test/offer?auction=5',
      'token' => 'escrow-token',
      'transaction_id' => 77,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, array $escrow_response): void {
    $payment->setRemoteId((string) $escrow_response['transaction_id']);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function getEscrowByOder(OrderInterface $order): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {}

  /**
   * {@inheritdoc}
   */
  public function getTransaction(int $transaction_id): array {
    return ['id' => $transaction_id];
  }

  /**
   * {@inheritdoc}
   */
  public function isOrderTransaction(OrderInterface $order, array $transaction): bool {
    foreach ($this->loadOrderPayments($order) as $payment) {
      if ((string) $payment->getRemoteId() === (string) ($transaction['id'] ?? '')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function loadOrderPayments(OrderInterface $order): array {
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    return $storage->loadMultipleByOrder($order);
  }

  /**
   * {@inheritdoc}
   */
  public function getWebhookUrl(): ?Url {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isValidWebhookToken(string $token): bool {
    return FALSE;
  }

}

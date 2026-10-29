<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer_escrow\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Matches the orders of offers negotiated on Escrow.com, or the others.
 *
 * Those orders are created with the gateway the settings name, or without
 * one named, with the first enabled Escrow Offer gateway. The gateways buyers
 * choose at checkout can then leave them out, and that gateway can keep to
 * them.
 *
 * @phpstan-consistent-constructor
 */
#[CommerceCondition(
  id: 'commerce_domains_order_escrow_offer',
  label: new TranslatableMarkup('Escrow.com offer'),
  entity_type: 'commerce_order',
  display_label: new TranslatableMarkup('Order is an offer negotiated on Escrow.com'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderEscrowOffer extends ConditionBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory, for the Escrow Offer gateway.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['escrow_offer' => TRUE] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['escrow_offer'] = [
      '#type' => 'radios',
      '#title' => $this->t('Orders'),
      '#options' => [
        1 => $this->t('Offers negotiated on Escrow.com'),
        0 => $this->t('Other orders'),
      ],
      '#default_value' => (int) $this->configuration['escrow_offer'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['escrow_offer'] = (bool) $values['escrow_offer'];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    assert($entity instanceof OrderInterface);
    $gateway = (string) $this->configFactory->get('commerce_domains_offer_escrow.settings')->get('gateway');
    $items = $entity->get('payment_gateway');
    if ($gateway !== '') {
      $escrow_offer = $items->target_id === $gateway;
    }
    else {
      // Offers then go to the first enabled Escrow Offer gateway: any counts.
      $escrow_offer = $items->entity instanceof PaymentGatewayInterface && $items->entity->getPluginId() === 'escrow_offer';
    }
    return $escrow_offer === (bool) $this->configuration['escrow_offer'];
  }

}

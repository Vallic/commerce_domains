<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Form;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for leases to own.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The payment gateway plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected PluginManagerInterface $gatewayManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->gatewayManager = $container->get('plugin.manager.commerce_payment_gateway');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_lease_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_domains_lease.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['default_down_payment'] = [
      '#type' => 'number',
      '#title' => $this->t('Down payment'),
      '#description' => $this->t("The share of the price paid at checkout, for domains whose sale terms set none (Lease down payment on the variation). The rest is paid in the lease's monthly payments."),
      '#min' => 1,
      '#max' => 90,
      '#field_suffix' => '%',
      '#config_target' => 'commerce_domains_lease.settings:default_down_payment',
    ];
    // Gateways by plugin, as the settings keep them: those that can charge
    // a saved card, and the rest, which the buyer pays through each time.
    $card = $manual = [];
    foreach ($this->gatewayManager->getDefinitions() as $id => $definition) {
      $label = (string) $definition['label'];
      if (is_subclass_of($definition['class'], SupportsStoredPaymentMethodsInterface::class)) {
        $card[$id] = $label;
      }
      else {
        $manual[$id] = $label;
      }
    }
    natcasesort($card);
    natcasesort($manual);
    $form['gateways'] = [
      '#type' => 'details',
      '#title' => $this->t('Paying'),
      '#open' => TRUE,
      '#description' => $this->t('A leased order offers only these gateways, when enabled.'),
    ];
    $form['gateways']['gateway_plugins'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Charged to the saved card'),
      '#description' => $this->t('The card the down payment was paid with is kept, and each monthly payment charged to it on cron.'),
      '#options' => $card,
      '#config_target' => new ConfigTarget('commerce_domains_lease.settings', 'gateway_plugins', fromConfig: fn($value) => (array) $value, toConfig: fn($value) => array_values(array_filter((array) $value))),
    ];
    $form['gateways']['manual_gateway_plugins'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Paid by the buyer each month'),
      '#description' => $this->t('Bank transfer, Wise and the like: when a payment is due the buyer is mailed the amount and how to pay it, and reminded every few days until it arrives. It counts once it is on the order: recorded on the lease page, received on the order, or reported by the gateway.'),
      '#options' => $manual,
      '#config_target' => new ConfigTarget('commerce_domains_lease.settings', 'manual_gateway_plugins', fromConfig: fn($value) => (array) $value, toConfig: fn($value) => array_values(array_filter((array) $value))),
    ];
    $form['missed'] = [
      '#type' => 'details',
      '#title' => $this->t('Missed payments'),
      '#open' => TRUE,
    ];
    $form['missed']['retry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Try a missed payment again after'),
      '#min' => 1,
      '#max' => 30,
      '#field_suffix' => $this->t('days'),
      '#config_target' => 'commerce_domains_lease.settings:retry_days',
    ];
    $form['missed']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Tries before the lease is in default'),
      '#description' => $this->t('The buyer is told after each failed try. A lease in default is charged no more until you charge it on its page, or cancel it.'),
      '#min' => 0,
      '#max' => 12,
      '#config_target' => 'commerce_domains_lease.settings:max_retries',
    ];
    $form['notify_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Tell this address about missed payments and completed leases'),
      '#description' => $this->t("Leave empty for the site's email address."),
      '#config_target' => 'commerce_domains_lease.settings:notify_mail',
    ];
    return parent::buildForm($form, $form_state);
  }

}

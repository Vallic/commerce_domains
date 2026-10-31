<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Plugin\Commerce\CheckoutPane;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_domains_lease\LeaseCalculator;
use Drupal\commerce_domains_lease\LeasePlan;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets the buyer pay in full or lease the domain to own.
 *
 * On the first step, so the payment step only offers the gateways a lease
 * can be paid with. Leases need an account, as the monthly payments go on
 * the card saved to it; buyers without one are asked to log in or sign up.
 */
#[CommerceCheckoutPane(
  id: 'commerce_domains_lease',
  label: new TranslatableMarkup('Lease to own'),
  admin_description: new TranslatableMarkup('Offers a lease to own on domains whose sale terms allow one: a down payment now, the rest monthly.'),
  default_step: 'order_information',
  wrapper_element: 'fieldset',
)]
class LeaseToOwn extends CheckoutPaneBase {

  // The option for paying in full.
  protected const FULL = 'full';

  /**
   * The lease calculator.
   *
   * @var \Drupal\commerce_domains_lease\LeaseCalculator
   */
  protected LeaseCalculator $calculator;

  /**
   * The currency formatter.
   *
   * @var \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   */
  protected CurrencyFormatterInterface $currencyFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->calculator = $container->get(LeaseCalculator::class);
    $instance->currencyFormatter = $container->get('commerce_price.currency_formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    return $this->calculator->terms($this->order) && $this->calculator->gateways();
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $options = [self::FULL => $this->t('Pay in full: @total', ['@total' => $this->money($this->order->getTotalPrice())])];
    foreach ($this->calculator->terms($this->order) as $months) {
      $plan = $this->calculator->plan($this->order, $months);
      if ($plan) {
        $options[$months] = $this->describe($plan);
      }
    }
    $pane_form['choice'] = [
      '#type' => 'radios',
      '#title' => $this->t('How would you like to pay?'),
      '#options' => $options,
      '#default_value' => (string) (LeaseCalculator::chosenMonths($this->order) ?? self::FULL),
    ];
    $pane_form['terms'] = [
      '#type' => 'item',
      '#markup' => $this->t('With a lease, the domain is yours once the last payment is in. The monthly payments are charged to the card you pay the down payment with.'),
    ];
    if ($this->order->getCustomer()->isAnonymous()) {
      $back = ['query' => ['destination' => Url::fromRoute('<current>')->toString()]];
      $pane_form['account'] = [
        '#type' => 'item',
        '#markup' => $this->t('A lease needs an account, which keeps your card for the monthly payments: <a href=":login">log in</a> or <a href=":register">create one</a>.', [
          ':login' => Url::fromRoute('user.login', [], $back)->toString(),
          ':register' => Url::fromRoute('user.register', [], $back)->toString(),
        ]),
      ];
    }
    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $choice = (string) $form_state->getValue([...$pane_form['#parents'], 'choice']);
    if ($choice !== self::FULL && $this->order->getCustomer()->isAnonymous()) {
      $form_state->setError($pane_form['choice'], $this->t('Log in or create an account to lease the domain.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $choice = (string) $form_state->getValue([...$pane_form['#parents'], 'choice']);
    $months = (int) $choice;
    if ($choice !== self::FULL && in_array($months, $this->calculator->terms($this->order), TRUE)) {
      $this->order->setData(LeaseCalculator::DATA_KEY, ['months' => $months]);
    }
    else {
      $this->order->unsetData(LeaseCalculator::DATA_KEY);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary(): array {
    $months = LeaseCalculator::chosenMonths($this->order);
    $plan = $months ? $this->calculator->plan($this->order, $months) : NULL;
    return $plan ? ['#plain_text' => (string) $this->describe($plan)] : [];
  }

  /**
   * Describes a lease's payments.
   */
  protected function describe(LeasePlan $plan): TranslatableMarkup {
    return $this->t('Lease to own: @down now, then @count monthly payments of @installment (@total in all)', [
      '@down' => $this->money($plan->downPayment),
      '@count' => $plan->months,
      '@installment' => $this->money($plan->installment),
      '@total' => $this->money($plan->total),
    ]);
  }

  /**
   * Formats an amount.
   */
  protected function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Form;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_lease\Entity\Lease;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\LeaseManager;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a lease, and lets the owner charge the next payment or cancel it.
 */
final class LeaseAdminForm extends FormBase {

  public function __construct(
    protected LeaseManager $leaseManager,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(LeaseManager::class),
      $container->get('commerce_price.currency_formatter'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_lease_admin';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LeaseInterface $commerce_domains_lease = NULL): array {
    $lease = $commerce_domains_lease;
    $form_state->set('lease_id', $lease->id());
    $order = $lease->getOrder();
    $date = fn(?int $time) => $time ? $this->dateFormatter->format($time, 'medium') : '';
    $order_link = $order ? ['data' => $order->toLink($order->getOrderNumber() ?: $order->id())->toRenderable()] : '';
    $monthly = $this->t('@made of @months made, @amount each', [
      '@made' => $lease->getPaymentsMade(),
      '@months' => $lease->getMonths(),
      '@amount' => $this->money($lease->getInstallment()),
    ]);
    $next = $lease->getNextDue() ? $this->t('@amount on @date', [
      '@amount' => $this->money($lease->getNextAmount()),
      '@date' => $date($lease->getNextDue()),
    ]) : '';
    $rows = [
      [$this->t('Domain'), $lease->getDomain()],
      [$this->t('State'), Lease::stateLabels()[$lease->getState()] ?? $lease->getState()],
      [$this->t('Order'), $order_link],
      [$this->t('Buyer'), $order?->getEmail() ?? ''],
      [$this->t('Total'), $this->money($lease->getTotal())],
      [$this->t('Down payment'), $this->money($lease->getDownPayment())],
      [$this->t('Monthly payments'), $monthly],
      [$this->t('Paid'), $this->money($lease->getPaid())],
      [$this->t('Left to pay'), $this->money($lease->getRemaining())],
      [$this->t('Next payment'), $next],
      [$this->t('Card'), $lease->getPaymentMethod()?->label() ?? $this->t('None saved')],
      [$this->t('Failed attempts'), $lease->getFailures()],
      [$this->t('Last failure'), (string) $lease->get('last_error')->value],
      [$this->t('Started'), $date((int) $lease->get('created')->value)],
    ];
    $form['details'] = [
      '#type' => 'table',
      '#rows' => $rows,
    ];

    $chargeable = $lease->isOpen() || $lease->getState() === LeaseInterface::DEFAULTED;
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['charge'] = [
      '#type' => 'submit',
      '#value' => $this->t('Charge @amount now', ['@amount' => $this->money($lease->getNextAmount())]),
      '#submit' => ['::charge'],
      '#access' => $chargeable && $lease->getRemaining()->isPositive(),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel lease'),
      '#submit' => ['::cancel'],
      '#access' => $chargeable,
      '#button_type' => 'danger',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Charges the next payment now.
   */
  public function charge(array &$form, FormStateInterface $form_state): void {
    $lease = $this->lease($form_state);
    if ($lease && $this->leaseManager->charge($lease)) {
      $this->messenger()->addStatus($this->t('The payment was charged.'));
    }
    else {
      $this->messenger()->addError($this->t('The payment failed: @error', ['@error' => (string) $lease?->get('last_error')->value]));
    }
  }

  /**
   * Cancels the lease.
   */
  public function cancel(array &$form, FormStateInterface $form_state): void {
    if ($lease = $this->lease($form_state)) {
      $this->leaseManager->cancel($lease);
      $this->messenger()->addStatus($this->t('The lease is canceled: nothing more is charged. The order and its payments stay.'));
    }
  }

  /**
   * Loads the lease afresh.
   */
  protected function lease(FormStateInterface $form_state): ?LeaseInterface {
    $lease = $this->entityTypeManager->getStorage('commerce_domains_lease')->load($form_state->get('lease_id'));
    return $lease instanceof LeaseInterface ? $lease : NULL;
  }

  /**
   * Formats an amount.
   */
  protected function money(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}

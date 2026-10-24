<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Form;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The seller's page for an offer: the details, and accept, decline or counter.
 */
final class OfferAdminForm extends FormBase {

  public function __construct(
    protected OfferManager $offerManager,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OfferManager::class),
      $container->get('commerce_price.currency_formatter'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_offer_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OfferInterface $commerce_domains_offer = NULL): array {
    $offer = $commerce_domains_offer;
    if (!$offer) {
      return $form;
    }
    $form_state->set('offer', $offer);
    $form['#title'] = $this->t('Offer on @domain', ['@domain' => $offer->getDomain()]);

    $product = $offer->getVariation()?->getProduct();
    $order = $offer->getOrder();
    $buyer = array_filter([$offer->getBuyerName(), $offer->getEmail(), (string) $offer->get('phone')->value]);
    $order_label = $order ? $this->t('Order @number', ['@number' => $order->getOrderNumber() ?: $order->id()]) : '';
    $rows = [
      [$this->t('Domain'), $product ? $product->toLink($offer->getDomain()) : $offer->getDomain()],
      [$this->t('Buyer'), implode(', ', $buyer)],
      [$this->t('Offer'), $this->money($offer->getAmount())],
      [$this->t('Counter-offer'), $this->money($offer->getCounterAmount())],
      [$this->t('State'), $offer->getState()->getLabel()],
      [$this->t('Made'), $this->dateFormatter->format((int) $offer->get('created')->value)],
      [$this->t('Message'), (string) $offer->get('message')->value],
      [$this->t('Order'), $order ? $order->toLink($order_label) : ''],
    ];
    $form['details'] = [
      '#type' => 'table',
      '#rows' => array_values(array_filter($rows, fn(array $row) => $row[1] !== '')),
    ];

    $state = $offer->getState()->getId();
    if (!in_array($state, ['pending', 'countered'], TRUE)) {
      return $form;
    }
    $form['actions'] = ['#type' => 'actions'];
    if ($state === 'pending') {
      $form['actions']['accept'] = [
        '#type' => 'submit',
        '#value' => $this->t('Accept @amount', ['@amount' => $this->money($offer->getAmount())]),
        '#submit' => ['::accept'],
        '#button_type' => 'primary',
      ];
    }
    $form['actions']['decline'] = [
      '#type' => 'submit',
      '#value' => $state === 'pending' ? $this->t('Decline') : $this->t('Withdraw the counter-offer'),
      '#submit' => ['::decline'],
    ];

    $form['counter'] = [
      '#type' => 'details',
      '#title' => $state === 'pending' ? $this->t('Counter-offer') : $this->t('Change the counter-offer'),
      '#open' => TRUE,
    ];
    $form['counter']['counter_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount'),
      '#field_suffix' => $offer->getAmount()?->getCurrencyCode(),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => $offer->getCounterAmount()?->getNumber(),
    ];
    $form['counter']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send counter-offer'),
      '#submit' => ['::counter'],
      '#validate' => ['::validateCounter'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Accepts the offer.
   */
  public function accept(array &$form, FormStateInterface $form_state): void {
    $offer = $this->offer($form_state);
    $this->offerManager->accept($offer);
    $this->messenger()->addStatus($this->t('Accepted. The buyer has been sent a link to pay.'));
  }

  /**
   * Declines the offer.
   */
  public function decline(array &$form, FormStateInterface $form_state): void {
    $this->offerManager->decline($this->offer($form_state));
    $this->messenger()->addStatus($this->t('Declined.'));
  }

  /**
   * Requires a counter-offer amount.
   */
  public function validateCounter(array &$form, FormStateInterface $form_state): void {
    if ((float) $form_state->getValue('counter_amount') <= 0) {
      $form_state->setErrorByName('counter_amount', $this->t('Enter the amount you would sell for.'));
    }
  }

  /**
   * Sends the counter-offer.
   */
  public function counter(array &$form, FormStateInterface $form_state): void {
    $offer = $this->offer($form_state);
    $currency = (string) $offer->getAmount()?->getCurrencyCode();
    $this->offerManager->counter($offer, new Price((string) $form_state->getValue('counter_amount'), $currency));
    $this->messenger()->addStatus($this->t('Counter-offer sent.'));
  }

  /**
   * Returns the offer the form is for.
   */
  protected function offer(FormStateInterface $form_state): OfferInterface {
    return $form_state->get('offer');
  }

  /**
   * Formats an amount.
   */
  protected function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

}

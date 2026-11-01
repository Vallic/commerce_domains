<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Form;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_price\Price;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
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
    $link_url = $offer->getPaymentLinkUrl();
    $months = $offer->getPaymentLinkLeaseMonths();
    $lease = $months ? $this->formatPlural($months, '1 monthly payment', '@count monthly payments') : '';
    $rows = [
      [$this->t('Domain'), $product ? $product->toLink($offer->getDomain()) : $offer->getDomain()],
      [$this->t('Buyer'), implode(', ', $buyer)],
      [$this->t('Offer'), $this->money($offer->getAmount())],
      [$this->t('Counter-offer'), $this->money($offer->getCounterAmount())],
      [$this->t('State'), $offer->getState()->getLabel()],
      [$this->t('Made'), $this->dateFormatter->format((int) $offer->get('created')->value)],
      [$this->t('Message'), (string) $offer->get('message')->value],
      [$this->t('Order'), $order ? $order->toLink($order_label) : ''],
      [$this->t('Paid through'), (string) $offer->getPaymentLinkProvider()?->label()],
      [$this->t('Payment link'), $link_url !== '' ? Link::fromTextAndUrl($link_url, Url::fromUri($link_url)) : ''],
      [$this->t('Lease to own'), $lease],
    ];
    $form['details'] = [
      '#type' => 'table',
      '#rows' => array_values(array_filter($rows, fn(array $row) => $row[1] !== '')),
    ];

    $state = $offer->getState()->getId();
    if ($offer->getSource() !== OfferInterface::SOURCE_SITE) {
      $form['elsewhere'] = [
        '#markup' => '<p>' . $this->t('This offer is negotiated on @channel; answer it there. Its state here follows the order.', ['@channel' => $offer->getSource()]) . '</p>',
      ];
      return $form;
    }
    if ($state === 'accepted') {
      return $this->buildAccepted($form, $offer);
    }
    if (!in_array($state, ['pending', 'countered'], TRUE)) {
      return $form;
    }
    $selector = ':input[name="payment"]';
    $options = [];
    $provider = $state === 'pending' ? $this->offerManager->paymentLinkProvider() : NULL;
    if ($provider) {
      $options['buy_now'] = $this->t('At once on @provider', ['@provider' => $provider->label()]);
      $options['lease'] = $this->t('Lease to own on @provider: a down payment, then monthly', ['@provider' => $provider->label()]);
    }
    else {
      $options['checkout'] = $this->t('At checkout on this site');
    }
    $link_providers = $state === 'pending' ? $this->offerManager->linkProviders() : [];
    if ($link_providers) {
      $options['pasted'] = $this->t('With a payment link I paste from a marketplace');
    }
    if (count($options) > 1) {
      $form['payment'] = [
        '#type' => 'radios',
        '#title' => $this->t('The buyer pays'),
        '#options' => $options,
        '#default_value' => array_key_first($options),
      ];
    }
    if ($provider) {
      $terms = $offer->getVariation() ? SaleTerms::fromVariation($offer->getVariation()) : new SaleTerms();
      $longest = $terms->leaseMonthsAt((float) $offer->getAmount()?->getNumber());
      $form['lease_months'] = [
        '#type' => 'number',
        '#title' => $this->t('Monthly payments'),
        '#min' => 1,
        '#max' => 120,
        '#default_value' => $longest ?: 12,
        '#states' => ['visible' => [$selector => ['value' => 'lease']]],
      ];
      $form['down_payment'] = [
        '#type' => 'number',
        '#title' => $this->t('Down payment'),
        '#field_suffix' => '%',
        '#min' => 0,
        '#max' => 99,
        '#default_value' => $terms->leaseDownPayment ?? 10,
        '#states' => ['visible' => [$selector => ['value' => 'lease']]],
      ];
    }
    if ($link_providers) {
      $form['link'] = $this->linkElements($link_providers) + [
        '#type' => 'container',
        '#states' => ['visible' => [$selector => ['value' => 'pasted']]],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    if ($state === 'pending') {
      $form['actions']['accept'] = [
        '#type' => 'submit',
        '#value' => $this->t('Accept @amount', ['@amount' => $this->money($offer->getAmount())]),
        '#submit' => ['::accept'],
        '#validate' => ['::validateAccept'],
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
   * Checks a lease can be paid by link.
   */
  public function validateAccept(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('payment') === 'pasted') {
      $this->validateLink($form, $form_state);
      return;
    }
    if ($form_state->getValue('payment') !== 'lease') {
      return;
    }
    $problem = $this->offerManager->leaseProblem($this->offer($form_state), (int) $form_state->getValue('lease_months'), (int) $form_state->getValue('down_payment'));
    if ($problem) {
      $form_state->setErrorByName('lease_months', $problem);
    }
  }

  /**
   * Accepts the offer.
   */
  public function accept(array &$form, FormStateInterface $form_state): void {
    $offer = $this->offer($form_state);
    $terms = match ($form_state->getValue('payment')) {
      'lease' => [
        'lease_months' => (int) $form_state->getValue('lease_months'),
        'down_payment' => (int) $form_state->getValue('down_payment'),
      ],
      'pasted' => $this->linkTerms($form_state),
      default => [],
    };
    try {
      $this->offerManager->accept($offer, $terms);
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $this->messenger()->addStatus($this->t('Accepted. The buyer has been sent a link to pay.'));
  }

  /**
   * Builds the page of an accepted offer: what is left to the seller.
   *
   * A link the seller pasted is marked paid, or unpaid, by hand. An offer
   * waiting at checkout can be sent a pasted link instead.
   */
  protected function buildAccepted(array $form, OfferInterface $offer): array {
    if ($offer->isPaymentLinkPasted()) {
      $form['pasted'] = [
        '#markup' => '<p>' . $this->t('The site can not see whether the buyer paid on @provider: mark the offer once they have.', ['@provider' => (string) $offer->getPaymentLinkProvider()?->label()]) . '</p>',
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['paid'] = [
        '#type' => 'submit',
        '#value' => $this->t('Paid'),
        '#submit' => ['::paid'],
        '#button_type' => 'primary',
      ];
      $form['actions']['unpaid'] = [
        '#type' => 'submit',
        '#value' => $this->t('Not paid: end the offer'),
        '#submit' => ['::unpaid'],
      ];
      return $form;
    }
    $order = $offer->getOrder();
    $providers = $this->offerManager->linkProviders();
    if ($offer->getPaymentLinkUrl() !== '' || !$providers || ($order && $order->getState()->getId() !== 'draft')) {
      return $form;
    }
    $form['link'] = $this->linkElements($providers) + [
      '#type' => 'details',
      '#title' => $this->t('Send a payment link from a marketplace instead'),
      '#description' => $order ? $this->t('The order waiting at checkout is canceled.') : NULL,
    ];
    $form['link']['send_link'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send the link'),
      '#validate' => ['::validateLink'],
      '#submit' => ['::sendLink'],
    ];
    return $form;
  }

  /**
   * Returns the fields of a pasted payment link.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface[] $providers
   *   The marketplaces to choose from.
   */
  protected function linkElements(array $providers): array {
    return [
      'link_provider' => [
        '#type' => 'select',
        '#title' => $this->t('Marketplace'),
        '#options' => array_map(fn($provider) => $provider->label(), $providers),
      ],
      'link_url' => [
        '#type' => 'url',
        '#title' => $this->t('Payment link'),
        '#description' => $this->t("The marketplace's checkout link for this buyer at the agreed price. The buyer gets it by email."),
        '#maxlength' => 2048,
      ],
      'link_lease_months' => [
        '#type' => 'number',
        '#title' => $this->t('Monthly payments'),
        '#description' => $this->t('For a lease to own; leave empty when paid at once.'),
        '#min' => 2,
        '#max' => 120,
      ],
    ];
  }

  /**
   * Requires a marketplace and an https link.
   */
  public function validateLink(array &$form, FormStateInterface $form_state): void {
    $url = trim((string) $form_state->getValue('link_url'));
    if (!isset($this->offerManager->linkProviders()[$form_state->getValue('link_provider')])) {
      $form_state->setErrorByName('link_provider', $this->t('Choose the marketplace.'));
    }
    if (!UrlHelper::isValid($url, TRUE) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
      $form_state->setErrorByName('link_url', $this->t('Paste the payment link, starting with https://.'));
    }
  }

  /**
   * Returns the terms of a pasted payment link.
   */
  protected function linkTerms(FormStateInterface $form_state): array {
    return [
      'link_provider' => $this->offerManager->linkProviders()[$form_state->getValue('link_provider')] ?? NULL,
      'link_url' => trim((string) $form_state->getValue('link_url')),
      'lease_months' => (int) $form_state->getValue('link_lease_months'),
    ];
  }

  /**
   * Sends the buyer of an accepted offer the pasted link.
   */
  public function sendLink(array &$form, FormStateInterface $form_state): void {
    $terms = $this->linkTerms($form_state);
    $this->offerManager->replaceWithLink($this->offer($form_state), $terms['link_provider'], $terms['link_url'], $terms['lease_months'] ?: NULL);
    $this->messenger()->addStatus($this->t('The buyer has been sent the link to pay.'));
  }

  /**
   * Marks the offer paid through its pasted link.
   */
  public function paid(array &$form, FormStateInterface $form_state): void {
    $this->offerManager->markPaid($this->offer($form_state));
    $this->messenger()->addStatus($this->t('Marked paid. The domain is off sale.'));
  }

  /**
   * Ends the offer whose pasted link was not paid.
   */
  public function unpaid(array &$form, FormStateInterface $form_state): void {
    $this->offerManager->markUnpaid($this->offer($form_state));
    $this->messenger()->addStatus($this->t('The offer has ended.'));
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

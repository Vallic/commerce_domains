<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Form;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_price\Price;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The buyer's page for an offer, reached from the offer emails.
 *
 * Shows where the offer stands; the buyer accepts or declines a
 * counter-offer, and pays an accepted offer.
 */
final class OfferBuyerForm extends FormBase {

  public function __construct(
    protected OfferManager $offerManager,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OfferManager::class),
      $container->get('commerce_price.currency_formatter'),
    );
  }

  /**
   * Allows the page to whoever has the offer's link.
   */
  public function access(OfferInterface $commerce_domains_offer, string $token): AccessResultInterface {
    $known = $commerce_domains_offer->getToken();
    return AccessResult::allowedIf($known !== '' && hash_equals($known, $token))->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_offer_buyer_form';
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
    $form['#title'] = $offer->getDomain();
    $args = [
      '@amount' => $this->money($offer->getAmount()),
      '@counter' => $this->money($offer->getCounterAmount()),
      '@agreed' => $this->money($offer->getAgreedAmount()),
    ];
    $status = match ($offer->getState()->getId()) {
      'pending' => $this->t('You offered @amount. The seller has not answered yet.', $args),
      'countered' => $this->t('You offered @amount. The seller would sell for @counter.', $args),
      'accepted' => $this->t('Agreed at @agreed. Pay to complete the purchase.', $args),
      'purchased' => $this->t('Bought for @agreed. Thank you!', $args),
      'declined' => $this->t('This offer was declined.'),
      default => $this->t('This offer has expired.'),
    };
    $elsewhere = $offer->getSource() !== OfferInterface::SOURCE_SITE;
    if ($elsewhere && in_array($offer->getState()->getId(), ['pending', 'accepted'], TRUE)) {
      $status = $this->t('This offer is negotiated on @channel.', ['@channel' => $offer->getSource()]);
    }
    $form['status'] = ['#markup' => '<p>' . $status . '</p>'];

    $form['actions'] = ['#type' => 'actions'];
    if ($offer->getState()->getId() === 'countered') {
      $form['actions']['accept'] = [
        '#type' => 'submit',
        '#value' => $this->t('Accept @counter', $args),
        '#submit' => ['::accept'],
        '#button_type' => 'primary',
      ];
      $form['actions']['decline'] = [
        '#type' => 'submit',
        '#value' => $this->t('Decline'),
        '#submit' => ['::decline'],
      ];
    }
    elseif ($offer->getSource() === OfferInterface::SOURCE_SITE && $offer->getState()->getId() === 'accepted' && $offer->getOrder()?->getState()->getId() === 'draft') {
      $form['actions']['pay'] = [
        '#type' => 'submit',
        '#value' => $this->t('Pay @agreed', $args),
        '#submit' => ['::pay'],
        '#button_type' => 'primary',
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Accepts the counter-offer, then goes on to pay.
   */
  public function accept(array &$form, FormStateInterface $form_state): void {
    $this->offerManager->accept($form_state->get('offer'));
    $this->pay($form, $form_state);
  }

  /**
   * Declines the counter-offer.
   */
  public function decline(array &$form, FormStateInterface $form_state): void {
    $this->offerManager->decline($form_state->get('offer'));
    $this->messenger()->addStatus($this->t('You declined the counter-offer.'));
  }

  /**
   * Sends the buyer to checkout for the accepted offer's order.
   */
  public function pay(array &$form, FormStateInterface $form_state): void {
    $url = $this->offerManager->checkoutUrl($form_state->get('offer'), $this->currentUser());
    if ($url) {
      $form_state->setRedirectUrl($url);
    }
  }

  /**
   * Formats an amount.
   */
  protected function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutFlow;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The checkout for a domain: details, transfer and payment, review.
 *
 * Selling a domain asks where the name should end up, which no other order
 * asks; it gets the middle step, with the choice of payment method, rather
 * than a corner of the sidebar.
 *
 * The last step is a review rather than the payment form, because Stripe
 * needs a page between choosing a card and being charged on which to run the
 * card's authentication.
 *
 * "Complete" is hidden, so the progress bar shows only the steps ahead; it
 * still shows as the current step on the confirmation page.
 */
#[CommerceCheckoutFlow(
  id: 'commerce_domains',
  label: new TranslatableMarkup('Domain: details, transfer and payment, review'),
)]
class DomainCheckoutFlow extends CheckoutFlowWithPanesBase {

  /**
   * {@inheritdoc}
   *
   * A step's next_label and previous_label are on the buttons leading to and
   * away from it: the button on "Your details" is defined on "transfer".
   */
  public function getSteps() {
    $steps = [
      'order_information' => [
        'label' => $this->t('Your details'),
        'has_sidebar' => TRUE,
        'previous_label' => $this->t('Back to your details'),
      ],
      'transfer' => [
        'label' => $this->t('Transfer and payment'),
        'has_sidebar' => TRUE,
        'next_label' => $this->t('Continue to transfer and payment'),
        'previous_label' => $this->t('Back to transfer and payment'),
      ],
      'review' => [
        'label' => $this->t('Review'),
        'has_sidebar' => TRUE,
        'next_label' => $this->t('Continue to review'),
        'previous_label' => $this->t('Go back'),
      ],
    ] + parent::getSteps();
    $steps['payment']['next_label'] = $this->t('Pay and complete order');
    $steps['complete']['hidden'] = TRUE;
    return $steps;
  }

}

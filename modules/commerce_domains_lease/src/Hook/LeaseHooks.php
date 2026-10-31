<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Hook;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\LeaseManager;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Commerce Domains Lease to Own.
 */
class LeaseHooks {

  use StringTranslationTrait;

  public function __construct(
    protected LeaseManager $leaseManager,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Queues the lease payments due; the queue charges them.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->leaseManager->queueDue();
  }

  /**
   * Implements hook_mail().
   *
   * Plain text, to the buyer ("to" => "buyer") or the owner. Sites change
   * them with hook_mail_alter().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $lease = $params['lease'] ?? NULL;
    if (!$lease instanceof LeaseInterface) {
      return;
    }
    $options = ['langcode' => $message['langcode']];
    $owner = ($params['to'] ?? 'buyer') === 'owner';
    // Paid by the buyer each month, rather than charged to a card.
    $manual = $lease->isManual();
    $args = [
      '@domain' => $lease->getDomain(),
      '@total' => $this->money($lease->getTotal()),
      '@paid' => $this->money($lease->getPaid()),
      '@remaining' => $this->money($lease->getRemaining()),
      '@next' => $this->money($lease->getNextAmount()),
      '@due' => $lease->getNextDue() ? $this->dateFormatter->format($lease->getNextDue(), 'custom', 'j F Y', NULL, $message['langcode']) : '',
      '@count' => $lease->getMonths(),
      '@installment' => $this->money($lease->getInstallment()),
      '@amount' => isset($params['amount']) && $params['amount'] instanceof Price ? $this->money($params['amount']) : '',
      '@error' => (string) $lease->get('last_error')->value,
      '@down' => $this->money($lease->getDownPayment()),
      '@how' => $manual ? $this->howToPay($lease, $options) : '',
    ];
    [$subject, $body] = match ($key) {
      'started' => [
        $this->t('Your lease of @domain', $args, $options),
        $manual ? [
          $this->t('Thank you: your lease to own @domain is set up.', $args, $options),
          $this->t('After the down payment of @down, @count monthly payments of @installment follow. We will ask you for each one, the first on @due. The domain is yours once @total is paid in all.', $args, $options),
        ] : [
          $this->t('Thank you: your lease to own @domain has started.', $args, $options),
          $this->t('@paid is paid. @count monthly payments of @installment follow, charged to the card you paid with, the next on @due. The domain is yours once @total is paid in all.', $args, $options),
        ],
      ],
      'paid' => [
        $this->t('Payment received for @domain', $args, $options),
        [
          $manual
            ? $this->t('We received @amount for your lease of @domain. @paid of @total is paid; the next payment, @next, is due on @due.', $args, $options)
            : $this->t('@amount was charged for your lease of @domain. @paid of @total is paid; the next payment, @next, is on @due.', $args, $options),
        ],
      ],
      'request' => [
        $this->t('Your payment for @domain is due', $args, $options),
        [
          $this->t('The next payment for your lease of @domain, @amount, is due now. @paid of @total is paid so far.', $args, $options),
          $args['@how'],
        ],
      ],
      'failed' => $manual ? [
        $owner ? $this->t('Lease payment not received: @domain', $args, $options) : $this->t('Reminder: your payment for @domain', $args, $options),
        $owner ? [
          $this->t('The payment of @next for the lease of @domain has not arrived. The buyer is reminded again on @due. Record it on the lease page once it does.', $args, $options),
        ] : [
          $this->t('We have not received @next for your lease of @domain yet. If you paid in the last few days, thank you, and please ignore this email.', $args, $options),
          $args['@how'],
        ],
      ] : [
        $owner ? $this->t('Lease payment failed: @domain', $args, $options) : $this->t('Your payment for @domain failed', $args, $options),
        [
          $owner
            ? $this->t('Charging @next for the lease of @domain failed: @error. It is tried again on @due.', $args, $options)
            : $this->t('We could not charge @next to your card for the lease of @domain. We will try again on @due; if your card changed, or your bank asks you to approve the payment, please reply to this email.', $args, $options),
        ],
      ],
      'defaulted' => [
        $owner ? $this->t('Lease in default: @domain', $args, $options) : $this->t('Your lease of @domain is overdue', $args, $options),
        [
          $owner
            ? ($manual
              ? $this->t('The payments of the lease of @domain have not arrived past the reminders: @paid of @total is paid. Nothing more is asked for until you record a payment on the lease page, or cancel the lease.', $args, $options)
              : $this->t('The payments of the lease of @domain failed past the retries: @paid of @total is paid. Nothing more is charged until you charge it on the lease page, or cancel the lease.', $args, $options))
            : $this->t('Your payments for the lease of @domain are overdue: @remaining is left to pay. Please reply to this email to settle it, or the domain may go back on sale.', $args, $options),
        ],
      ],
      'completed' => [
        $owner ? $this->t('Lease paid in full: @domain', $args, $options) : $this->t('@domain is paid in full', $args, $options),
        [
          $owner
            ? $this->t('The lease of @domain is paid in full (@total). Its handover can start.', $args, $options)
            : $this->t('Thank you: @domain is paid in full. We will be in touch to hand it over to you.', $args, $options),
        ],
      ],
      default => [NULL, []],
    };
    if ($subject === NULL) {
      return;
    }
    $message['subject'] = $subject;
    $message['body'] = array_map('strval', $body);
  }

  /**
   * Says how to pay a manual lease's next payment.
   *
   * A Wise pay link for the amount, or the gateway's own payment
   * instructions, as a bank transfer gateway has them; with the order
   * number to quote.
   */
  protected function howToPay(LeaseInterface $lease, array $options): string {
    $order = $lease->getOrder();
    $plugin = $lease->getPaymentGateway()?->getPlugin();
    $reference = $order ? ($order->getOrderNumber() ?: (string) $order->id()) : '';
    if ($order && $plugin && method_exists($plugin, 'generateQuickPayUrl')) {
      return (string) $this->t('Pay it with Wise: @link', ['@link' => $plugin->generateQuickPayUrl($order)], $options);
    }
    $instructions = $plugin ? trim(strip_tags((string) ($plugin->getConfiguration()['instructions']['value'] ?? ''))) : '';
    return $instructions !== ''
      ? (string) $this->t("Pay it as before, quoting order @reference:\n\n@instructions", [
        '@reference' => $reference,
        '@instructions' => $instructions,
      ], $options)
      : (string) $this->t('Pay it as before, quoting order @reference, or reply to this email to ask how.', ['@reference' => $reference], $options);
  }

  /**
   * Formats an amount.
   */
  protected function money(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}

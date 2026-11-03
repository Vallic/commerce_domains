<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\Hook;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_checkout\UnpaidOrders;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Releases domains held by unpaid orders, and the mails about it.
 */
class UnpaidOrderHooks {

  use StringTranslationTrait;

  public function __construct(
    protected UnpaidOrders $unpaidOrders,
    protected DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'commerce_price.currency_formatter')]
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->unpaidOrders->process();
  }

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $order = $params['order'] ?? NULL;
    if (!$order instanceof OrderInterface) {
      return;
    }
    $options = ['langcode' => $message['langcode']];
    $owner = ($params['to'] ?? 'buyer') === 'owner';
    $total = $order->getTotalPrice();
    $args = [
      '@domain' => implode(', ', $this->unpaidOrders->domainNames($order)),
      '@order' => $order->getOrderNumber() ?: $order->id(),
      '@total' => $total ? $this->money($total) : '',
      '@until' => $this->dateFormatter->format($this->unpaidOrders->holdEnds($order), 'custom', 'j F Y', NULL, $message['langcode']),
      '@days' => $this->unpaidOrders->holdDays(),
      '@amount' => isset($params['amount']) && $params['amount'] instanceof Price ? $this->money($params['amount']) : '',
    ];
    [$subject, $body] = match ($key) {
      'unpaid_reminder' => [
        $this->t('Your payment for @domain', $args, $options),
        [
          $this->t('We have not received your payment of @total for @domain (order @order) yet.', $args, $options),
          $this->t('We are holding the domain for you until @until. After that the order is canceled and the domain goes back on sale. If you have already paid, thank you, and please reply to this email so we can look for it.', $args, $options),
        ],
      ],
      'unpaid_canceled' => $owner ? [
        $this->t('Unpaid order canceled: @domain', $args, $options),
        [
          $this->t('Order @order for @domain was not paid within @days days, so it was canceled and the domain went back on sale.', $args, $options),
        ],
      ] : [
        $this->t('Your order for @domain was canceled', $args, $options),
        [
          $this->t('We did not receive your payment for @domain (order @order) within @days days, so the order is canceled and the domain is back on sale.', $args, $options),
          $this->t('If you have sent the payment, or still want the domain, please reply to this email.', $args, $options),
        ],
      ],
      'unpaid_late_payment' => [
        $this->t('Payment for a canceled order: @domain', $args, $options),
        [
          $this->t('A payment of @amount arrived for order @order, which was canceled. @domain may have sold again since, so please refund it or sell the domain to this buyer by hand.', $args, $options),
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
   * Formats an amount.
   */
  protected function money(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}

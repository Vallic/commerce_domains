<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Where the buyer wants an order's domain to go.
 *
 * A push to the buyer's account at the registrar holding the domain, or a
 * transfer to another registrar. The checkout pane keeps it on the order,
 * and Commerce Domains Handover shows it to the seller.
 */
final class Transfer {

  // The order data key it is kept under.
  public const DATA_KEY = 'commerce_domains_transfer';

  // To the buyer's account at the registrar holding the domain.
  public const PUSH = 'push';

  // To another registrar.
  public const TRANSFER = 'transfer';

  public function __construct(
    public readonly string $method = self::PUSH,
    public readonly string $account = '',
    public readonly string $registrar = '',
  ) {}

  /**
   * Returns the buyer's answer on an order, if any.
   */
  public static function fromOrder(OrderInterface $order): ?self {
    $data = $order->getData(self::DATA_KEY);
    if (!is_array($data)) {
      return NULL;
    }
    return new self(
      ($data['method'] ?? '') === self::TRANSFER ? self::TRANSFER : self::PUSH,
      (string) ($data['account'] ?? ''),
      (string) ($data['registrar'] ?? ''),
    );
  }

  /**
   * Keeps the answer on an order.
   */
  public function toOrder(OrderInterface $order): void {
    $order->setData(self::DATA_KEY, [
      'method' => $this->method,
      'account' => $this->account,
      'registrar' => $this->registrar,
    ]);
  }

  /**
   * Says where the domain goes, as the buyer and the seller read it.
   *
   * @param string|null $current_registrar
   *   The name of the registrar holding the domain, which a push stays at;
   *   NULL or '' when none is recorded.
   */
  public function describe(?string $current_registrar): TranslatableMarkup {
    if ($this->method === self::TRANSFER) {
      return new TranslatableMarkup('Transfer to @registrar', ['@registrar' => $this->registrar]);
    }
    if ((string) $current_registrar === '') {
      return new TranslatableMarkup('Push to the account at the current registrar: @account', ['@account' => $this->account]);
    }
    return new TranslatableMarkup('Push to the @registrar account: @account', [
      '@registrar' => $current_registrar,
      '@account' => $this->account,
    ]);
  }

  /**
   * Returns the registrar holding the order's domain, if recorded.
   */
  public static function currentRegistrar(OrderInterface $order): ?string {
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      $product = $variation instanceof ProductVariationInterface ? $variation->getProduct() : NULL;
      if ($product && $product->hasField('domain_registrar') && ($registrar = $product->get('domain_registrar')->entity)) {
        return (string) $registrar->label();
      }
    }
    return NULL;
  }

}

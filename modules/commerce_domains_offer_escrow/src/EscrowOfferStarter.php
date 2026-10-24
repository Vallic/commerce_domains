<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer_escrow;

use Drupal\commerce\Context;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_offer\OfferPolicy;
use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts the negotiation of an offer on Escrow.com.
 *
 * Creates the order on the Escrow Offer gateway, opens the Escrow
 * transaction, and records the offer as negotiated there. The buyer then
 * makes the offer on Escrow.com, where both sides settle the price.
 */
class EscrowOfferStarter {

  // The offer source for offers negotiated on Escrow.com.
  public const SOURCE = 'escrow';

  // The currencies Escrow.com transacts in.
  public const CURRENCIES = ['USD', 'EUR', 'GBP', 'AUD', 'CAD'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected OfferManager $offerManager,
    protected OfferPolicy $offerPolicy,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the Escrow Offer gateway offers are negotiated on.
   *
   * The configured one, otherwise the first enabled Escrow Offer gateway.
   */
  public function gateway(): ?PaymentGatewayInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $id = (string) $this->configFactory->get('commerce_domains_offer_escrow.settings')->get('gateway');
    $gateways = $id !== '' ? $storage->loadMultiple([$id]) : $storage->loadByProperties(['plugin' => 'escrow_offer']);
    foreach ($gateways as $gateway) {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway */
      if ($gateway->status() && $gateway->getPlugin() instanceof EscrowGatewayInterface) {
        return $gateway;
      }
    }
    return NULL;
  }

  /**
   * Returns whether offers in a currency can be negotiated on Escrow.com.
   */
  public function supports(string $currency): bool {
    return in_array($currency, self::CURRENCIES, TRUE) && $this->gateway() !== NULL;
  }

  /**
   * Starts the negotiation, and returns the page the buyer makes it on.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The domain's variation.
   * @param array $buyer
   *   The buyer: mail, and optionally name, phone and message.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer's account; anonymous for buyers without one.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store the offer is made in.
   * @param \Drupal\commerce_price\Price|null $amount
   *   The amount the buyer means to offer, if they named one.
   *
   * @return string
   *   The Escrow.com page, for the buyer's email.
   *
   * @throws \Exception
   *   When Escrow.com refuses the transaction; nothing is kept then.
   */
  public function start(ProductVariationInterface $variation, array $buyer, AccountInterface $account, StoreInterface $store, ?Price $amount = NULL): string {
    $gateway = $this->gateway();
    $price = $this->offerPolicy->price($variation, new Context($account, $store));
    if (!$gateway || !$price || !in_array($price->getCurrencyCode(), self::CURRENCIES, TRUE)) {
      throw new \InvalidArgumentException('This domain cannot be negotiated on Escrow.com.');
    }
    /** @var \Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface $plugin */
    $plugin = $gateway->getPlugin();

    $order = $this->createOrder($variation, $price, $buyer, $account, $store, $gateway);
    try {
      $escrow = $plugin->initiateEscrow($order);
    }
    catch (\Exception $e) {
      $this->logger->error('Escrow.com refused the offer on @domain: @message', [
        '@domain' => $variation->getProduct()?->label(),
        '@message' => $e->getMessage(),
      ]);
      $order->delete();
      throw $e;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'type' => 'escrow_payment',
      'payment_gateway' => $gateway->id(),
      'state' => 'new',
      'order_id' => $order->id(),
      'amount' => $order->getTotalPrice(),
    ]);
    $payment->save();
    $plugin->createPayment($payment, $escrow);

    $offer = $this->offerManager->submit($variation, $amount, $buyer, $account, $store, self::SOURCE);
    $offer->setOrder($order)->save();
    return $escrow['make_offer_page'] . '&email=' . rawurlencode($buyer['mail']);
  }

  /**
   * Returns the offer an Escrow Offer order belongs to.
   */
  public function offerFor(OrderInterface $order): ?OfferInterface {
    $offers = $this->entityTypeManager->getStorage('commerce_domains_offer')->loadByProperties([
      'order' => $order->id(),
      'source' => self::SOURCE,
    ]);
    $offer = reset($offers);
    return $offer instanceof OfferInterface ? $offer : NULL;
  }

  /**
   * Creates the order Escrow.com negotiates, at the price the buyer sees.
   *
   * It skips checkout: it starts in validation, numbered, as Escrow Offer
   * orders do, and Escrow.com's webhooks move it on.
   */
  protected function createOrder(ProductVariationInterface $variation, Price $price, array $buyer, AccountInterface $account, StoreInterface $store, PaymentGatewayInterface $gateway): OrderInterface {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $item_storage */
    $item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item = $item_storage->createFromPurchasableEntity($variation, ['quantity' => 1]);
    $order_item->setUnitPrice($price, TRUE);
    $order_item->save();

    /** @var \Drupal\commerce_order\Entity\OrderItemTypeInterface $item_type */
    $item_type = $this->entityTypeManager->getStorage('commerce_order_item_type')->load($order_item->bundle());
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->entityTypeManager->getStorage('commerce_order_type')->load($item_type->getOrderTypeId());
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->create([
      'type' => $order_type->id(),
      'store_id' => $store->id(),
      'uid' => $account->id(),
      'mail' => $buyer['mail'],
      'payment_gateway' => $gateway->id(),
      'state' => 'validation',
      'cart' => FALSE,
    ]);
    $order->save();
    $order->setOrderNumber($order_type->getNumberPattern()?->getPlugin()->generate($order) ?? (string) $order->id());

    // A buyer with an account brings their billing details along.
    if ($account->isAuthenticated()) {
      /** @var \Drupal\profile\ProfileStorageInterface $profile_storage */
      $profile_storage = $this->entityTypeManager->getStorage('profile');
      /** @var \Drupal\profile\Entity\ProfileInterface|null $profile */
      $profile = $profile_storage->loadByUser($this->entityTypeManager->getStorage('user')->load($account->id()), 'customer');
      if ($profile) {
        /** @var \Drupal\profile\Entity\ProfileInterface $billing */
        $billing = $profile_storage->create(['type' => 'customer', 'uid' => 0]);
        $billing->populateFromProfile($profile);
        $billing->save();
        $order->setBillingProfile($billing);
      }
    }

    $order->addItem($order_item);
    $order->recalculateTotalPrice();
    $order->setPlacedTime($this->time->getRequestTime());
    $order->save();
    return $order;
  }

}

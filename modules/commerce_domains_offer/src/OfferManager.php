<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer;

use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Moves offers through their workflow, with the emails and the order.
 *
 * The seller answers pending offers; the buyer answers counter-offers.
 * Accepting creates a draft order for the domain at the agreed amount, which
 * the buyer pays through checkout from the link in their email.
 */
class OfferManager {

  // The order item data key that points back to the offer.
  public const ORDER_ITEM_KEY = 'commerce_domains_offer';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected CartSessionInterface $cartSession,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a new offer and tells both sides.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The domain's variation.
   * @param \Drupal\commerce_price\Price $amount
   *   The amount offered.
   * @param array $buyer
   *   The buyer: mail, and optionally name, phone and message.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer's account; anonymous for buyers without one.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store the offer was made in.
   * @param string $source
   *   Where the offer is negotiated. The buyer only gets the site's
   *   confirmation for offers made on the site; channels send their own.
   */
  public function submit(ProductVariationInterface $variation, ?Price $amount, array $buyer, AccountInterface $account, ?StoreInterface $store = NULL, string $source = OfferInterface::SOURCE_SITE): OfferInterface {
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    $offer = $this->entityTypeManager->getStorage('commerce_domains_offer')->create([
      'variation' => $variation,
      'store' => $store ?? $this->defaultStore($variation),
      'amount' => $amount,
      'uid' => $account->id(),
      'mail' => $buyer['mail'],
      'name' => $buyer['name'] ?? '',
      'phone' => $buyer['phone'] ?? '',
      'message' => $buyer['message'] ?? '',
      'source' => $source,
    ]);
    $offer->save();
    if ($source === OfferInterface::SOURCE_SITE) {
      $this->notifyBuyer($offer, 'received');
    }
    $this->notifySeller($offer, 'new_offer');
    return $offer;
  }

  /**
   * Counters an offer with another amount; the buyer decides.
   */
  public function counter(OfferInterface $offer, Price $amount): void {
    $offer->setCounterAmount($amount);
    $offer->getState()->applyTransitionById('counter');
    $offer->save();
    $this->notifyBuyer($offer, 'countered');
  }

  /**
   * Accepts an offer and creates its order.
   *
   * @param \Drupal\commerce_domains_offer\Entity\OfferInterface $offer
   *   The offer: pending when the seller accepts, countered when the buyer
   *   accepts the counter-offer.
   */
  public function accept(OfferInterface $offer): void {
    $by_buyer = $offer->getState()->getId() === 'countered';
    $offer->setOrder($this->createOrder($offer));
    $offer->getState()->applyTransitionById('accept');
    $offer->save();
    $this->notifyBuyer($offer, 'accepted');
    if ($by_buyer) {
      $this->notifySeller($offer, 'buyer_accepted');
    }
  }

  /**
   * Declines an offer, or the buyer declines the counter-offer.
   */
  public function decline(OfferInterface $offer): void {
    $by_buyer = $offer->getState()->getId() === 'countered';
    $offer->getState()->applyTransitionById('decline');
    $offer->save();
    if ($by_buyer) {
      $this->notifySeller($offer, 'buyer_declined');
    }
    else {
      $this->notifyBuyer($offer, 'declined');
    }
  }

  /**
   * Marks offers purchased once their order is placed.
   */
  public function orderPlaced(OrderInterface $order): void {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_offer');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('order', $order->id())
      ->condition('state', 'accepted')
      ->execute();
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    foreach ($storage->loadMultiple($ids) as $offer) {
      $offer->getState()->applyTransitionById('purchase');
      $offer->save();
    }
  }

  /**
   * Expires offers left unanswered or unpaid too long.
   *
   * @return int
   *   The number of offers expired.
   */
  public function expireStale(): int {
    $days = (int) $this->configFactory->get('commerce_domains_offer.settings')->get('expire_days');
    if ($days < 1) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_domains_offer');
    // Offers negotiated elsewhere end there.
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('state', ['pending', 'countered', 'accepted'], 'IN')
      ->condition('source', OfferInterface::SOURCE_SITE)
      ->condition('changed', $this->time->getRequestTime() - $days * 86400, '<')
      ->execute();
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    foreach ($storage->loadMultiple($ids) as $offer) {
      // An unpaid order goes too, so the buyer can't pay for it later.
      $order = $offer->getOrder();
      if ($order && $order->getState()->getId() === 'draft') {
        $order->getState()->applyTransitionById('cancel');
        $order->save();
      }
      $offer->getState()->applyTransitionById('expire');
      $offer->save();
      $this->notifyBuyer($offer, 'expired');
    }
    return count($ids);
  }

  /**
   * Lets an account pay an accepted offer's order, and returns where.
   *
   * The buyer's link stands in for a login: an account takes over an order
   * made without one, and an anonymous visitor gets it in their session.
   *
   * @return \Drupal\Core\Url|null
   *   The checkout page, or NULL when there's no order left to pay.
   */
  public function checkoutUrl(OfferInterface $offer, AccountInterface $account): ?Url {
    $order = $offer->getOrder();
    if ($offer->getState()->getId() !== 'accepted' || !$order || $order->getState()->getId() !== 'draft') {
      return NULL;
    }
    if ($account->isAuthenticated()) {
      if ((int) $order->getCustomerId() !== (int) $account->id()) {
        $order->setCustomerId($account->id());
        $order->save();
      }
    }
    elseif (!$this->cartSession->hasCartId($order->id())) {
      $this->cartSession->addCartId($order->id());
    }
    return Url::fromRoute('commerce_checkout.form', ['commerce_order' => $order->id()]);
  }

  /**
   * Returns the buyer's page for an offer.
   */
  public function buyerUrl(OfferInterface $offer): Url {
    return Url::fromRoute('commerce_domains_offer.buyer', [
      'commerce_domains_offer' => $offer->id(),
      'token' => $offer->getToken(),
    ], ['absolute' => TRUE]);
  }

  /**
   * Creates the draft order for an accepted offer.
   */
  protected function createOrder(OfferInterface $offer): OrderInterface {
    $variation = $offer->getVariation();
    $amount = $offer->getAgreedAmount();
    if (!$variation || !$amount) {
      throw new \InvalidArgumentException(sprintf('Offer %s has no domain or amount to order.', $offer->id()));
    }
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $item_storage */
    $item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item = $item_storage->createFromPurchasableEntity($variation, ['quantity' => 1]);
    // Overridden, so refreshing the order keeps the agreed amount.
    $order_item->setUnitPrice($amount, TRUE);
    $order_item->setData(self::ORDER_ITEM_KEY, $offer->id());
    $order_item->save();

    /** @var \Drupal\commerce_order\Entity\OrderItemTypeInterface $item_type */
    $item_type = $this->entityTypeManager->getStorage('commerce_order_item_type')->load($order_item->bundle());
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->create([
      'type' => $item_type->getOrderTypeId(),
      'store_id' => ($offer->getStore() ?? $this->defaultStore($variation))?->id(),
      'uid' => $offer->getOwnerId(),
      'mail' => $offer->getEmail(),
      'state' => 'draft',
      'cart' => FALSE,
    ]);
    $order->addItem($order_item);
    $order->save();
    $this->logger->info('Offer @offer on @domain accepted at @amount: order @order.', [
      '@offer' => $offer->id(),
      '@domain' => $offer->getDomain(),
      '@amount' => $amount->__toString(),
      '@order' => $order->id(),
    ]);
    return $order;
  }

  /**
   * Returns the first store the domain is sold in.
   */
  protected function defaultStore(ProductVariationInterface $variation): ?StoreInterface {
    $stores = $variation->getProduct()?->getStores() ?? [];
    return $stores ? reset($stores) : NULL;
  }

  /**
   * Emails the buyer about their offer.
   */
  protected function notifyBuyer(OfferInterface $offer, string $key): void {
    $this->mail($key, $offer->getEmail(), $offer);
  }

  /**
   * Emails the seller about an offer.
   */
  protected function notifySeller(OfferInterface $offer, string $key): void {
    // Without an address of their own, offers go where Commerce Domains
    // Owner sends the owner's mail, if it is installed and has one.
    $to = (string) $this->configFactory->get('commerce_domains_offer.settings')->get('notify_mail')
      ?: (string) $this->configFactory->get('commerce_domains_owner.settings')->get('notify_mail')
      ?: (string) $offer->getStore()?->getEmail()
      ?: (string) $this->configFactory->get('system.site')->get('mail');
    if ($to !== '') {
      $this->mail($key, $to, $offer);
    }
  }

  /**
   * Sends one of the offer emails.
   */
  protected function mail(string $key, string $to, OfferInterface $offer): void {
    $params = [
      'offer' => $offer,
      'buyer_url' => $this->buyerUrl($offer)->toString(),
      'admin_url' => $offer->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
    $this->mailManager->mail('commerce_domains_offer', $key, $to, $offer->language()->getId(), $params);
  }

}

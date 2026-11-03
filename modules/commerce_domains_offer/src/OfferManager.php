<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer;

use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\PaymentLinksInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Moves offers through their workflow, with the emails and the order.
 *
 * The seller answers pending offers; the buyer answers counter-offers.
 * Accepting creates a draft order for the domain at the agreed amount, which
 * the buyer pays through checkout from the link in their email. With a
 * payment link provider set (payment_link_provider), accepting makes the
 * provider's payment link instead, and cron marks the offer purchased once
 * the provider reports it paid; a link that can't be made falls back to the
 * order. The seller can also paste a link made on a marketplace without an
 * API for it (Atom, Dynadot, Afternic…), and marks the offer paid by hand.
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
    protected Catalog $catalog,
    protected Availability $availability,
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
   * @param array $terms
   *   For a lease to own paid by link: lease_months and down_payment
   *   (percent). For a link the seller pasted: link_provider (the provider
   *   entity) and link_url, with lease_months for a lease.
   *
   * @throws \RuntimeException
   *   When a lease's payment link can't be made; the offer is unchanged.
   */
  public function accept(OfferInterface $offer, array $terms = []): void {
    $by_buyer = $offer->getState()->getId() === 'countered';
    $lease = !empty($terms['lease_months']);
    if (!empty($terms['link_url']) && ($terms['link_provider'] ?? NULL) instanceof ProviderInterface) {
      $this->pasteLink($offer, $terms['link_provider'], $terms['link_url'], $lease ? (int) $terms['lease_months'] : NULL);
    }
    elseif (!$this->linkPayment($offer, $terms)) {
      // A lease is only paid by link: it never falls back to a checkout
      // for the full price.
      if ($lease) {
        throw new \RuntimeException((string) new TranslatableMarkup('The payment link for the lease could not be made; see the log. The offer is unchanged.'));
      }
      $offer->setOrder($this->createOrder($offer));
    }
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
   * Expires the offers a canceled order had bought.
   *
   * The buyer hears about the order, so not about the offer as well.
   */
  public function orderCanceled(OrderInterface $order): void {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_offer');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('order', $order->id())
      ->condition('state', 'purchased')
      ->execute();
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    foreach ($storage->loadMultiple($ids) as $offer) {
      $offer->getState()->applyTransitionById('expire');
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
    if ($offer->getPaymentLinkUrl() !== '') {
      return $offer->getState()->getId() === 'accepted' ? Url::fromUri($offer->getPaymentLinkUrl()) : NULL;
    }
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
   * Returns the provider set to make payment links, when it can.
   */
  public function paymentLinkProvider(): ?ProviderInterface {
    $id = (string) $this->configFactory->get('commerce_domains_offer.settings')->get('payment_link_provider');
    $provider = $id !== '' ? $this->entityTypeManager->getStorage('commerce_domains_provider')->load($id) : NULL;
    return $provider instanceof ProviderInterface && $provider->status() && $provider->hasCapability(PaymentLinksInterface::class)
      && $provider->getPlugin()->getSetupError() === NULL ? $provider : NULL;
  }

  /**
   * Makes the payment link for an accepted offer, when a provider is set.
   *
   * @param \Drupal\commerce_domains_offer\Entity\OfferInterface $offer
   *   The offer.
   * @param array $terms
   *   For a lease to own: lease_months and down_payment (percent).
   *
   * @return bool
   *   Whether the offer got one.
   */
  protected function linkPayment(OfferInterface $offer, array $terms = []): bool {
    $provider = $this->paymentLinkProvider();
    $amount = $offer->getAgreedAmount();
    $plugin = $provider?->getPlugin();
    if (!$plugin instanceof PaymentLinksInterface || !$amount) {
      return FALSE;
    }
    $price = $this->paymentLinkPrice($offer, $amount);
    if (!$price) {
      $this->logger->warning('No payment link for offer @offer on @domain: @amount can not be converted to a currency @provider takes.', [
        '@offer' => $offer->id(),
        '@domain' => $offer->getDomain(),
        '@amount' => $amount->__toString(),
        '@provider' => $provider->label(),
      ]);
      return FALSE;
    }
    try {
      $link = $plugin->createPaymentLink(strtolower($offer->getDomain()), $price, $offer->getEmail(), [
        'buyer_fee' => (int) $this->configFactory->get('commerce_domains_offer.settings')->get('payment_link_buyer_fee'),
      ] + array_intersect_key($terms, array_flip(['lease_months', 'down_payment'])));
    }
    catch (ProviderException $e) {
      $this->logger->warning('No @provider payment link for offer @offer on @domain, so it gets a checkout order: @error', [
        '@provider' => $provider->label(),
        '@offer' => $offer->id(),
        '@domain' => $offer->getDomain(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
    $offer->setPaymentLink($provider, $link, empty($terms['lease_months']) ? NULL : (int) $terms['lease_months']);
    $this->logger->info('Offer @offer on @domain accepted at @amount: paid through @provider (@id).', [
      '@offer' => $offer->id(),
      '@domain' => $offer->getDomain(),
      '@amount' => $price->__toString(),
      '@provider' => $provider->label(),
      '@id' => $link->id,
    ]);
    return TRUE;
  }

  /**
   * Returns the providers whose payment links a seller can paste.
   *
   * The marketplaces: those listing by API and those the owner lists on by
   * hand.
   *
   * @return \Drupal\commerce_domains\Entity\ProviderInterface[]
   *   The enabled marketplaces, keyed by ID, by label.
   */
  public function linkProviders(): array {
    $providers = array_filter(
      $this->entityTypeManager->getStorage('commerce_domains_provider')->loadByProperties(['status' => TRUE]),
      fn($provider) => $provider instanceof ProviderInterface && $provider->isMarketplace(),
    );
    uasort($providers, fn(ProviderInterface $a, ProviderInterface $b) => strnatcasecmp((string) $a->label(), (string) $b->label()));
    return $providers;
  }

  /**
   * Sends the buyer of an accepted offer a link the seller pasted instead.
   *
   * For an offer accepted to checkout, e.g. a counter-offer the buyer
   * accepted: its unpaid order is canceled, and the buyer gets the link.
   *
   * @throws \LogicException
   *   When the offer isn't accepted, already has a link, or its order is
   *   past paying.
   */
  public function replaceWithLink(OfferInterface $offer, ProviderInterface $provider, string $url, ?int $lease_months = NULL): void {
    $order = $offer->getOrder();
    if ($offer->getState()->getId() !== 'accepted' || $offer->getPaymentLinkUrl() !== '' || ($order && $order->getState()->getId() !== 'draft')) {
      throw new \LogicException(sprintf('Offer %s can not take a payment link.', $offer->id()));
    }
    if ($order) {
      $order->getState()->applyTransitionById('cancel');
      $order->save();
      $offer->set('order', NULL);
    }
    $this->pasteLink($offer, $provider, $url, $lease_months);
    $offer->save();
    $this->notifyBuyer($offer, 'accepted');
  }

  /**
   * Marks an offer paid through a link the seller pasted.
   *
   * The offer is purchased and the domain goes off sale; Commerce Domains
   * Owner records the sale.
   */
  public function markPaid(OfferInterface $offer): void {
    if ($offer->getState()->getId() !== 'accepted' || !$offer->isPaymentLinkPasted()) {
      throw new \LogicException(sprintf('Offer %s is not waiting on a pasted payment link.', $offer->id()));
    }
    $offer->getState()->applyTransitionById('purchase');
    $offer->save();
    $product = $offer->getVariation()?->getProduct();
    if ($product) {
      $this->availability->markProductSold($product);
    }
    $this->logger->info('Offer @offer on @domain marked paid through @provider.', [
      '@offer' => $offer->id(),
      '@domain' => $offer->getDomain(),
      '@provider' => $offer->getPaymentLinkProvider()?->label(),
    ]);
  }

  /**
   * Ends an offer whose pasted payment link the buyer didn't pay.
   */
  public function markUnpaid(OfferInterface $offer): void {
    if ($offer->getState()->getId() !== 'accepted' || !$offer->isPaymentLinkPasted()) {
      throw new \LogicException(sprintf('Offer %s is not waiting on a pasted payment link.', $offer->id()));
    }
    $offer->getState()->applyTransitionById('expire');
    $offer->save();
    $this->notifyBuyer($offer, 'expired');
  }

  /**
   * Gives an offer a payment link the seller pasted.
   */
  protected function pasteLink(OfferInterface $offer, ProviderInterface $provider, string $url, ?int $lease_months): void {
    $offer->setPaymentLink($provider, new PaymentLink('', $url, PaymentLink::PENDING), $lease_months ?: NULL);
    $this->logger->info('Offer @offer on @domain: paid through a link pasted from @provider.', [
      '@offer' => $offer->id(),
      '@domain' => $offer->getDomain(),
      '@provider' => $provider->label(),
    ]);
  }

  /**
   * Says why a lease can't be paid by link, or NULL when it can.
   *
   * Each payment, the down payment and every monthly one, must reach the
   * provider's minimum.
   */
  public function leaseProblem(OfferInterface $offer, int $months, int $down_payment): ?TranslatableMarkup {
    $plugin = $this->paymentLinkProvider()?->getPlugin();
    $amount = $offer->getAgreedAmount();
    $price = $amount ? $this->paymentLinkPrice($offer, $amount) : NULL;
    if (!$plugin instanceof PaymentLinksInterface || !$price) {
      return new TranslatableMarkup('No payment link provider can take this offer.');
    }
    $total = (float) $price->getNumber();
    $down = $total * $down_payment / 100;
    $installment = $months > 0 ? ($total - $down) / $months : 0;
    $minimum = $plugin->paymentLinkMinimum();
    if (($down_payment > 0 && $down < $minimum) || $installment < $minimum) {
      return new TranslatableMarkup('Each payment must be at least @minimum @currency: here the down payment is @down and each monthly payment @installment.', [
        '@minimum' => $minimum,
        '@currency' => $price->getCurrencyCode(),
        '@down' => number_format($down, 2),
        '@installment' => number_format($installment, 2),
      ]);
    }
    return NULL;
  }

  /**
   * Returns an amount in a currency the payment link provider takes.
   *
   * @param \Drupal\commerce_domains_offer\Entity\OfferInterface $offer
   *   The offer, whose domain's prices convert the amount.
   * @param \Drupal\commerce_price\Price $amount
   *   The amount, e.g. the agreed one.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The amount, or NULL without a provider or a way to convert it.
   */
  public function paymentLinkPrice(OfferInterface $offer, Price $amount): ?Price {
    $plugin = $this->paymentLinkProvider()?->getPlugin();
    $currencies = $plugin instanceof PaymentLinksInterface ? $plugin->paymentLinkCurrencies() : [];
    if (!$currencies) {
      return NULL;
    }
    if (in_array($amount->getCurrencyCode(), $currencies, TRUE)) {
      return $amount;
    }
    $item = $this->catalog->load([strtolower($offer->getDomain())])[strtolower($offer->getDomain())] ?? NULL;
    $currency = reset($currencies);
    $number = $item && $currency ? $item->amount($amount, $currency) : NULL;
    return $number ? new Price((string) round($number, 2), $currency) : NULL;
  }

  /**
   * Reads the payment links of accepted offers, and settles those that ended.
   *
   * Paid: the offer is purchased and the domain goes off sale. Canceled or
   * expired: the offer expires. A pasted link is taken as paid once the
   * domain is recorded as sold on its marketplace (Commerce Domains Owner),
   * e.g. from the marketplace's sales report.
   *
   * @return int
   *   How many offers changed.
   */
  public function checkPaymentLinks(): int {
    $changed = $this->settlePastedLinks();
    $storage = $this->entityTypeManager->getStorage('commerce_domains_offer');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('state', 'accepted')
      ->exists('payment_link_id')
      ->execute();
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    foreach ($storage->loadMultiple($ids) as $offer) {
      $plugin = $offer->getPaymentLinkProvider()?->getPlugin();
      if (!$plugin instanceof PaymentLinksInterface) {
        continue;
      }
      try {
        $link = $plugin->fetchPaymentLink($offer->getPaymentLinkId());
      }
      catch (ProviderException $e) {
        $this->logger->warning('Reading the payment link of offer @offer failed: @error', [
          '@offer' => $offer->id(),
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
      if ($link->state === PaymentLink::PAID) {
        $offer->getState()->applyTransitionById('purchase');
        $offer->save();
        $product = $offer->getVariation()?->getProduct();
        if ($product) {
          $this->availability->markProductSold($product);
        }
        $this->notifySeller($offer, 'paid_by_link');
        $changed++;
      }
      elseif ($link->state === PaymentLink::ENDED) {
        $offer->getState()->applyTransitionById('expire');
        $offer->save();
        $changed++;
      }
    }
    return $changed;
  }

  /**
   * Purchases the offers with pasted links whose domain sold there.
   *
   * @return int
   *   How many offers were purchased.
   */
  protected function settlePastedLinks(): int {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_offer');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('state', 'accepted')
      ->exists('payment_link_url')
      ->notExists('payment_link_id')
      ->execute();
    $changed = 0;
    $products = $this->entityTypeManager->getStorage('commerce_product');
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    foreach ($storage->loadMultiple($ids) as $offer) {
      // As saved now: the sale may have been recorded since the variation
      // was loaded.
      $product_id = $offer->getVariation()?->getProductId();
      $product = $product_id ? $products->loadUnchanged($product_id) : NULL;
      $provider = $offer->getPaymentLinkProvider();
      if ($product instanceof ProductInterface && $provider && $product->hasField('domain_sold') && $product->hasField('domain_sold_via')
        && !$product->get('domain_sold')->isEmpty() && $product->get('domain_sold_via')->target_id === $provider->id()) {
        $this->markPaid($offer);
        $this->notifySeller($offer, 'paid_by_link');
        $changed++;
      }
    }
    return $changed;
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

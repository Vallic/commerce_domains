<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer_escrow;

use Drupal\commerce\Context;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_offer\OfferPolicy;
use Drupal\commerce_escrow\EscrowOfferOrders;
use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts the negotiation of an offer on Escrow.com.
 *
 * Commerce Escrow creates a locked draft order on the Escrow Offer gateway and
 * opens the auction. The buyer then makes the offer on Escrow.com, where both
 * sides settle the price, and the first webhook records it as an offer here.
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
    protected EscrowOfferOrders $offerOrders,
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
   * Only a locked draft order exists until the buyer makes the offer there; see
   * recordOffer().
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The domain's variation.
   * @param array $buyer
   *   The buyer: mail, and optionally name, phone and message.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer's account; anonymous for buyers without one.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store the offer is made in.
   *
   * @return string
   *   The Escrow.com page, for the buyer's email.
   *
   * @throws \Exception
   *   When Escrow.com refuses the transaction; nothing is kept then.
   */
  public function start(ProductVariationInterface $variation, array $buyer, AccountInterface $account, StoreInterface $store): string {
    $gateway = $this->gateway();
    $price = $this->offerPolicy->price($variation, new Context($account, $store));
    if (!$gateway || !$price || !in_array($price->getCurrencyCode(), self::CURRENCIES, TRUE)) {
      throw new \InvalidArgumentException('This domain cannot be negotiated on Escrow.com.');
    }
    try {
      $result = $this->offerOrders->start($gateway, $variation, $price, $buyer['mail'], $account, $store, $buyer);
    }
    catch (\Exception $e) {
      $this->logger->error('Escrow.com refused the offer on @domain: @message', [
        '@domain' => $variation->getProduct()?->label(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
    return $result['response']['make_offer_page'] . '&email=' . rawurlencode($buyer['mail']);
  }

  /**
   * Records the offer of an order whose buyer has now made it on Escrow.com.
   *
   * @return \Drupal\commerce_domains_offer\Entity\OfferInterface|null
   *   The offer, or NULL when the order isn't waiting for one.
   */
  public function recordOffer(OrderInterface $order): ?OfferInterface {
    $buyer = $order->getData(EscrowOfferOrders::DATA_KEY);
    $variation = $order->getItems() ? $order->getItems()[0]->getPurchasedEntity() : NULL;
    if ($buyer === NULL || !$variation instanceof ProductVariationInterface) {
      return NULL;
    }
    $offer = $this->offerManager->submit($variation, NULL, $buyer, $order->getCustomer(), $order->getStore(), self::SOURCE);
    $offer->setOrder($order)->save();
    // The offer holds the buyer's details from here.
    $order->unsetData(EscrowOfferOrders::DATA_KEY);
    $order->save();
    return $offer;
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

}

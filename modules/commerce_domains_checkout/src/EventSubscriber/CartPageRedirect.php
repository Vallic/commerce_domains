<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\EventSubscriber;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends the cart page somewhere useful.
 *
 * A domain is bought one at a time and the buy button goes straight to
 * checkout, so the page only turns up through an old link, a bookmark or a
 * crawler, and then shows a table with a single row and a second button to
 * press.
 *
 * A visitor with a cart is sent to its checkout, at whichever step they had
 * reached. A visitor without one is sent to the front page rather than an empty
 * table.
 *
 * The cart module itself stays: checkout depends on it, and the session it
 * keeps is what lets a guest reach their own checkout at all.
 */
final class CartPageRedirect implements EventSubscriberInterface {

  /**
   * Constructs a new CartPageRedirect object.
   *
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   */
  public function __construct(
    protected CartProviderInterface $cartProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After RouterListener, which runs at 32 and is what puts the route name
    // on the request.
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Redirects the cart page.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if ($event->getRequest()->attributes->get('_route') !== 'commerce_cart.page') {
      return;
    }

    $carts = array_filter($this->cartProvider->getCarts(), static function ($cart) {
      return $cart->hasItems();
    });

    if (!$carts) {
      $event->setResponse(new RedirectResponse(Url::fromRoute('<front>')->toString()));

      return;
    }

    // More than one cart is possible — one per store — so the one the buyer
    // touched last is the one they meant.
    uasort($carts, static function ($a, $b) {
      return $b->getChangedTime() <=> $a->getChangedTime();
    });
    $cart = reset($carts);

    $event->setResponse(new RedirectResponse(
      Url::fromRoute('commerce_checkout.form', ['commerce_order' => $cart->id()])->toString()
    ));
  }

}

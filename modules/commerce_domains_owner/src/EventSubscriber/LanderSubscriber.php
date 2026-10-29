<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\EventSubscriber;

use Drupal\commerce_domains\Controller\LanderController;
use Drupal\commerce_domains_owner\Checklist;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Notes when visitors come through the lander, for the dashboard checklist.
 *
 * That is the sign parked domains point at it. Written after the response
 * is sent, and at most hourly, so busy parked domains cost nothing.
 */
class LanderSubscriber implements EventSubscriberInterface {

  // Seconds between two writes.
  protected const INTERVAL = 3600;

  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  /**
   * Records the time of a visit through the lander.
   */
  public function onTerminate(TerminateEvent $event): void {
    // By its controller: sites may serve it on a path of their own too.
    $controller = $event->getRequest()->attributes->get('_controller');
    if (!is_string($controller) || ltrim($controller, '\\') !== LanderController::class . '::redirect') {
      return;
    }
    $now = $this->time->getRequestTime();
    if ($now - (int) $this->state->get(Checklist::LANDER_KEY, 0) >= self::INTERVAL) {
      $this->state->set(Checklist::LANDER_KEY, $now);
    }
  }

}

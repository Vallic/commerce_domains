<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace_test\EventSubscriber;

use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent;
use Drupal\commerce_domains_marketplace\Event\SyncFailedEvent;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the marketplace events in State, as the owner's notifier sees them.
 *
 * State key commerce_domains_marketplace_test.events: a list of
 * [event name, provider ID, details].
 */
final class EventRecorder implements EventSubscriberInterface {

  public const KEY = 'commerce_domains_marketplace_test.events';

  public function __construct(protected StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MarketplaceEvents::SOLD_ELSEWHERE => 'onSold',
      MarketplaceEvents::SYNC_FAILED => 'onFailed',
    ];
  }

  /**
   * Records a sale: the domain, its product ID and the status.
   */
  public function onSold(SoldElsewhereEvent $event): void {
    $this->record(MarketplaceEvents::SOLD_ELSEWHERE, (string) $event->provider->id(), [
      'domain' => $event->domain,
      'product' => $event->product ? (int) $event->product->id() : NULL,
      'status' => $event->status,
    ]);
  }

  /**
   * Records a failure: the errors.
   */
  public function onFailed(SyncFailedEvent $event): void {
    $this->record(MarketplaceEvents::SYNC_FAILED, (string) $event->provider->id(), $event->errors);
  }

  /**
   * Appends an event.
   */
  protected function record(string $name, string $provider_id, array $details): void {
    $events = $this->state->get(self::KEY, []);
    $events[] = [$name, $provider_id, $details];
    $this->state->set(self::KEY, $events);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\EventSubscriber;

use Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent;
use Drupal\commerce_domains_marketplace\Event\SyncFailedEvent;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records sales marketplaces report, and tells the owner about them.
 *
 * Also tells the owner when a marketplace sync fails. Listens by event
 * name, so it does nothing without Commerce Domains Marketplace.
 */
class MarketplaceSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected SaleRecorder $recorder,
    protected Notifier $notifier,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_domains_marketplace.sold_elsewhere' => 'onSoldElsewhere',
      'commerce_domains_marketplace.sync_failed' => 'onSyncFailed',
    ];
  }

  /**
   * Records the sale on the domain, and mails the owner.
   */
  public function onSoldElsewhere(SoldElsewhereEvent $event): void {
    if ($event->product) {
      $this->recorder->recordElsewhere($event->product, $event->provider, $this->time->getRequestTime());
    }
    $this->notifier->soldElsewhere($event->provider, $event->domain, $event->product, $event->status);
  }

  /**
   * Records the failure, and mails the owner once a day.
   */
  public function onSyncFailed(SyncFailedEvent $event): void {
    $this->notifier->syncFailed($event->provider, $event->errors);
  }

}

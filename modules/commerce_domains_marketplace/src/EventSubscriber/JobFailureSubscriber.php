<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\EventSubscriber;

use Drupal\advancedqueue\Event\AdvancedQueueEvents;
use Drupal\advancedqueue\Event\JobEvent;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\Event\SyncFailedEvent;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Reports queued pushes and sale checks that failed for good.
 *
 * Advanced Queue fires its failure event once a job is out of retries, or
 * failed in a way that is never retried, so an outage it rides out is not
 * reported.
 */
class JobFailureSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AdvancedQueueEvents::JOB_FAILURE => 'onFailure'];
  }

  /**
   * Dispatches MarketplaceEvents::SYNC_FAILED for the job's marketplace.
   */
  public function onFailure(JobEvent $event): void {
    $job = $event->getJob();
    if (!in_array($job->getType(), [SyncScheduler::JOB_TYPE, SyncScheduler::CHECK_JOB_TYPE], TRUE)) {
      return;
    }
    $provider_id = $job->getPayload()['provider'] ?? NULL;
    $provider = is_string($provider_id) ? $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider_id) : NULL;
    if ($provider instanceof ProviderInterface) {
      // The job's message is the API client's, which leaves out credentials.
      $errors = ['' => (string) ($job->getMessage() ?: 'The job failed.')];
      $this->eventDispatcher->dispatch(new SyncFailedEvent($provider, $errors), MarketplaceEvents::SYNC_FAILED);
    }
  }

}

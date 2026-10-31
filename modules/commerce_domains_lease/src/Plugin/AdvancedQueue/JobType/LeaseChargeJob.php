<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_domains_lease\Entity\LeaseInterface;
use Drupal\commerce_domains_lease\LeaseManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Charges the payment a lease has due.
 *
 * A failed payment is not retried by the queue: the lease records it and
 * cron queues it again once its retry is due (see LeaseManager).
 */
#[AdvancedQueueJobType(
  id: self::ID,
  label: new TranslatableMarkup('Lease payment'),
  max_retries: 0,
  allow_duplicates: FALSE,
)]
final class LeaseChargeJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  public const ID = 'commerce_domains_lease_charge';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LeaseManager $leaseManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get(LeaseManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $lease = $this->entityTypeManager->getStorage('commerce_domains_lease')->load($job->getPayload()['lease_id'] ?? 0);
    // Paid by hand, canceled or deleted since it was queued.
    if (!$lease instanceof LeaseInterface || !$lease->isOpen() || ($lease->getNextDue() ?? PHP_INT_MAX) > time()) {
      return JobResult::success('Skipped: nothing is due.');
    }
    return $this->leaseManager->charge($lease)
      ? JobResult::success('Paid.')
      : JobResult::success('The payment failed; the lease records the retry.');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Hook;

use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron for Commerce Domains Marketplace.
 *
 * Advanced Queue processes the queue on cron itself; this only adds the
 * hourly sale checks to it.
 */
class CronHooks {

  public function __construct(
    protected SyncScheduler $scheduler,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->scheduler->queueChecks();
  }

}

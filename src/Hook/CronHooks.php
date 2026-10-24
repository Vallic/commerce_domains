<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron for Commerce Domains.
 */
class CronHooks {

  public function __construct(
    protected DomainNameParser $parser,
    protected RegistrarSync $registrarSync,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Refreshes the Public Suffix List weekly, and registrations daily.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->parser->refresh();
    $this->registrarSync->cron();
  }

}

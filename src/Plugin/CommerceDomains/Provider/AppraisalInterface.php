<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Appraisal;

/**
 * A provider that estimates what domains are worth.
 *
 * Any domain can be appraised, not only those in the account. Services limit
 * how many a day; past the limit, appraise() throws.
 */
interface AppraisalInterface extends ProviderPluginInterface {

  /**
   * Appraises one domain.
   *
   * @param string $name
   *   The domain, normalized.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   On failure, e.g. the day's limit reached (retryable).
   */
  public function appraise(string $name): Appraisal;

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * Capability: reads the domains registered in the provider account.
 */
interface RegistrarInterface extends ProviderPluginInterface {

  /**
   * Fetches the domains registered in the account.
   *
   * @param string[]|null $names
   *   Normalized domains to fetch, or NULL for the whole account.
   *
   * @return array<string, \Drupal\commerce_domains\Registration>
   *   Registrations keyed by domain name. Domains that are not in the
   *   account are missing.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function fetchRegistrations(?array $names = NULL): array;

}

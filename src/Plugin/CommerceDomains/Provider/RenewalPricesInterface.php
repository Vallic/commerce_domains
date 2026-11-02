<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * A provider that publishes what a year's renewal costs, by extension.
 */
interface RenewalPricesInterface extends ProviderPluginInterface {

  /**
   * Returns what a year's renewal costs, at the prices of the account.
   *
   * @param string[] $tlds
   *   The extensions wanted, such as com and co.uk; empty for all.
   *
   * @return array<string, \Drupal\commerce_price\Price>
   *   Prices keyed by extension, in lower case and without a leading dot. An
   *   extension the provider doesn't sell is left out.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the prices can't be read.
   */
  public function renewalPrices(array $tlds = []): array;

}

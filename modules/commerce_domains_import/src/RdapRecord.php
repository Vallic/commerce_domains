<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

/**
 * A domain's registration as its registry's RDAP service publishes it.
 */
final class RdapRecord {

  public function __construct(
    // The registrar's name, e.g. "GoDaddy.com, LLC"; NULL when not given.
    public readonly ?string $registrar,
    // The registrar's IANA ID, when given.
    public readonly ?string $ianaId = NULL,
    // When the registration runs out, as a Unix time.
    public readonly ?int $expires = NULL,
  ) {}

}

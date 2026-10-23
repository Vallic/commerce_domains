<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * A domain as a registrar holds it in the account.
 */
final class Registration {

  public function __construct(
    // The domain in lowercase ASCII (IDNA) form.
    public readonly string $name,
    // When the registration runs out, as a Unix time.
    public readonly ?int $expires,
    // Whether transfers are locked; NULL when the registrar doesn't say.
    public readonly ?bool $locked = NULL,
    // Whether it renews automatically; NULL when the registrar doesn't say.
    public readonly ?bool $autoRenew = NULL,
    // The registrar's own status, e.g. "active" or "redemption".
    public readonly ?string $status = NULL,
  ) {}

}

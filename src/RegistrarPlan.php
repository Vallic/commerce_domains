<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * What a registrar sync would change in the catalog.
 */
final class RegistrarPlan {

  /**
   * Expiry dates to update: old and new Unix time, keyed by domain.
   *
   * @var array<string, array{0: int|null, 1: int}>
   */
  public array $expires = [];

  /**
   * Auto-renew settings to update: old and new, keyed by domain.
   *
   * Only for products with an auto-renew field (the costs trait of Commerce
   * Domains Owner), and registrars that report the setting.
   *
   * @var array<string, array{0: bool|null, 1: bool}>
   */
  public array $autoRenew = [];

  /**
   * Registration dates to update: old and new Unix time, keyed by domain.
   *
   * Only for registrars that report the date.
   *
   * @var array<string, array{0: int|null, 1: int}>
   */
  public array $registered = [];

  /**
   * Product IDs of each domain to update, keyed by domain.
   *
   * @var array<string, int[]>
   */
  public array $products = [];

  /**
   * How many catalog domains already have the registrar's details.
   */
  public int $unchanged = 0;

  /**
   * Catalog domains registered here that the account doesn't have.
   *
   * Transferred away, expired, or recorded at the wrong registrar.
   *
   * @var string[]
   */
  public array $missing = [];

  /**
   * Domains in the account that the catalog records elsewhere.
   *
   * Applying the plan records this registrar on them.
   *
   * The catalog's registrar provider ID, or NULL when it has none.
   *
   * @var array<string, string|null>
   */
  public array $elsewhere = [];

  /**
   * Domains in the account that the catalog doesn't sell.
   *
   * @var string[]
   */
  public array $unknown = [];

  /**
   * The account's registrations, keyed by domain.
   *
   * @var array<string, \Drupal\commerce_domains\Registration>
   */
  public array $registrations = [];

  /**
   * Whether applying the plan would change nothing.
   */
  public function isEmpty(): bool {
    return !$this->expires && !$this->elsewhere && !$this->autoRenew && !$this->registered;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * Capability: changes domains in the registrar account.
 *
 * Every method acts on the registrar at once, so callers ask a person first.
 * An operation the registrar's API doesn't offer throws, with a message that
 * says where to do it instead.
 */
interface DomainManagementInterface extends RegistrarInterface {

  /**
   * Fetches a domain's nameservers.
   *
   * @return string[]
   *   Host names in lowercase, in the registrar's order. Empty when the domain
   *   uses the registrar's own parking or DNS rather than nameservers.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function getNameservers(string $name): array;

  /**
   * Replaces a domain's nameservers.
   *
   * @param string $name
   *   The normalized domain.
   * @param string[] $nameservers
   *   Two or more host names.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function setNameservers(string $name, array $nameservers): void;

  /**
   * Locks a domain against transfers, or unlocks it.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function setTransferLock(string $name, bool $locked): void;

  /**
   * Turns automatic renewal on or off.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function setAutoRenew(string $name, bool $auto_renew): void;

  /**
   * Fetches the code another registrar needs to transfer the domain in.
   *
   * Registrars usually only give one for an unlocked domain.
   *
   * @return string
   *   The code, or an empty string when the registrar emails it to the
   *   domain's administrative contact instead.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function getAuthCode(string $name): string;

}

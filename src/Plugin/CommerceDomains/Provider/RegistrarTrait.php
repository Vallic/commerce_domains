<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Registration;

/**
 * Implements RegistrarInterface from the records a registrar API returns.
 *
 * The using plugin fetches the records and maps one record to a Registration.
 */
trait RegistrarTrait {

  /**
   * Fetches the account's domain records.
   *
   * @param string[]|null $names
   *   The domains asked for, or NULL for the whole account. Plugins whose API
   *   reads single domains can fetch just these when there are few
   *   (see fewNames()); others return the whole account.
   *
   * @return iterable<array>
   *   Domain records as the API returns them.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  abstract protected function registrationRecords(?array $names): iterable;

  /**
   * Maps a domain record to a registration.
   *
   * @return \Drupal\commerce_domains\Registration|null
   *   The registration, or NULL for a record without a domain name.
   */
  abstract protected function registration(array $record): ?Registration;

  /**
   * {@inheritdoc}
   */
  public function fetchRegistrations(?array $names = NULL): array {
    $registrations = [];
    foreach ($this->registrationRecords($names) as $record) {
      if ($registration = $this->registration($record)) {
        $registrations[$registration->name] = $registration;
      }
    }
    return $names === NULL ? $registrations : array_intersect_key($registrations, array_flip($names));
  }

  /**
   * Returns whether the names are few enough to fetch one by one.
   *
   * Beyond 20, listing the whole account takes fewer requests.
   *
   * @phpstan-assert-if-true string[] $names
   */
  protected static function fewNames(?array $names): bool {
    return $names !== NULL && count($names) <= 20;
  }

}

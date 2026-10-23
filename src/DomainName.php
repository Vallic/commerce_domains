<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * A registrable domain name, split into its label and extension.
 */
final class DomainName {

  public function __construct(
    // The whole name in lowercase ASCII, IDNs as A-labels (xn--).
    public readonly string $name,
    // The name in Unicode, for display.
    public readonly string $unicode,
    // The part before the extension: "blala" in blala.co.uk.
    public readonly string $label,
    // The public suffix: "co.uk" in blala.co.uk.
    public readonly string $extension,
  ) {}

  /**
   * Normalizes a name to the form marketplaces and registrars key it by.
   *
   * Lowercase, without surrounding space or a trailing dot, IDNs as A-labels.
   */
  public static function normalize(string $name): string {
    $name = rtrim(mb_strtolower(trim($name)), '.');
    if ($name !== '' && preg_match('/[^\x20-\x7e]/', $name) && function_exists('idn_to_ascii')) {
      $name = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) ?: $name;
    }
    return $name;
  }

}

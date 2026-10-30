<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * What an appraisal service estimates a domain is worth.
 */
final class Appraisal {

  public function __construct(
    // The domain in lowercase ASCII (IDNA) form.
    public readonly string $name,
    // The estimated value in whole US dollars; NULL when there is none.
    public readonly ?int $value,
    // The service's score of the name, e.g. 7.7 out of 10.
    public readonly ?float $score = NULL,
    // How many extensions the same name is registered in.
    public readonly ?int $tldCount = NULL,
    // What raises the value, as the service words it.
    public readonly array $positive = [],
    // What lowers it.
    public readonly array $negative = [],
  ) {}

}

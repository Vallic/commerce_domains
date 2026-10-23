<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

/**
 * A request to a provider's API failed as a whole.
 *
 * Per-domain failures inside a batch are returned as results instead.
 */
class ProviderException extends \RuntimeException {

  public function __construct(
    string $message,
    // Whether the same request can succeed later without code changes: rate
    // limits, outages, timeouts, and missing or rejected credentials.
    public readonly bool $retryable = FALSE,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

/**
 * A registrar lookup could not be made, e.g. no RDAP service for the TLD.
 *
 * The message says why, for the import report.
 */
final class LookupException extends \RuntimeException {}

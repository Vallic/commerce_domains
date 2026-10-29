<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A handover step that cannot be taken, and what to tell the seller.
 *
 * The exception's own message is the untranslated text, for logs; the
 * reason is what the page shows. The registrar's own refusals are
 * ProviderExceptions instead.
 */
class HandoverException extends \RuntimeException {

  public function __construct(
    // What the seller is told, in their language.
    public readonly TranslatableMarkup $reason,
  ) {
    parent::__construct((string) new FormattableMarkup($reason->getUntranslatedString(), $reason->getArguments()));
  }

}

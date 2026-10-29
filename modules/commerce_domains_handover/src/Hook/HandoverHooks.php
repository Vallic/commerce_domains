<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Commerce Domains Handover.
 */
class HandoverHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      // The buyer's emails; see HandoverMail.
      'commerce_domains_handover_mail' => [
        'variables' => [
          'order_entity' => NULL,
          'handover' => NULL,
          'step' => NULL,
          'heading' => NULL,
          'intro' => [],
          'steps' => [],
          'auth_code' => NULL,
          'outro' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_HOOK() for the handover emails.
   *
   * One template per email, e.g. commerce-domains-handover-mail--pushed.
   */
  #[Hook('theme_suggestions_commerce_domains_handover_mail')]
  public function mailSuggestions(array $variables): array {
    return $variables['step'] ? ['commerce_domains_handover_mail__' . $variables['step']] : [];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover;

use Drupal\commerce\MailHandlerInterface;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The buyer's emails about a handover, in the store's name.
 *
 * Sent through Commerce's mail handler, from the store's address, with the
 * commerce_domains_handover_mail template, which a theme overrides as it
 * does the order receipt (per email, with __started, __auth_code, __pushed or
 * __transferred). Commerce Log records each one on the order.
 *
 * Only the auth code email carries the code, and it is copied to nobody.
 */
class HandoverMail {

  use StringTranslationTrait;

  // What happens next, when the handover starts.
  public const STARTED = 'started';

  // The auth code, for a transfer to another registrar.
  public const AUTH_CODE = 'auth_code';

  // The domain was pushed to the buyer's account at the same registrar.
  public const PUSHED = 'pushed';

  // The domain is the buyer's.
  public const TRANSFERRED = 'transferred';

  public function __construct(
    protected MailHandlerInterface $mailHandler,
  ) {}

  /**
   * Emails the buyer.
   *
   * @param \Drupal\commerce_domains_handover\Entity\HandoverInterface $handover
   *   The handover.
   * @param string $step
   *   What the email is about: one of the constants.
   * @param string|null $auth_code
   *   The auth code, for the AUTH_CODE email; never passed anywhere else.
   *
   * @return bool
   *   Whether the email was sent.
   */
  public function send(HandoverInterface $handover, string $step, #[\SensitiveParameter] ?string $auth_code = NULL): bool {
    $order = $handover->getOrder();
    $to = (string) $order?->getEmail();
    if (!$order || $to === '') {
      return FALSE;
    }
    $customer = $order->getCustomer();
    $langcode = $customer->isAuthenticated() ? $customer->getPreferredLangcode() : NULL;
    $body = [
      '#theme' => 'commerce_domains_handover_mail',
      '#order_entity' => $order,
      '#handover' => $handover,
      '#step' => $step,
    ];
    // Rendered once the mail handler has switched to the buyer's language.
    foreach ($this->content($handover, $step) as $name => $value) {
      $body['#' . $name] = $value;
    }
    if ($step === self::AUTH_CODE) {
      $body['#auth_code'] = $auth_code;
    }

    // No Bcc or copy to the store: the auth code email is the buyer's alone.
    $params = [
      'id' => 'commerce_domains_handover_' . $step,
      'order' => $order,
    ];
    if ($store = $order->getStore()) {
      $params['from'] = $store->getEmailFromHeader();
    }
    if ($langcode) {
      $params['langcode'] = $langcode;
    }
    return $this->mailHandler->sendMail($to, $this->subject($handover, $step, $langcode), $body, $params);
  }

  /**
   * Returns an email's subject, in the buyer's language.
   */
  protected function subject(HandoverInterface $handover, string $step, ?string $langcode): string {
    $args = ['@domain' => $handover->getDomain()];
    $options = $langcode ? ['langcode' => $langcode] : [];
    return (string) match ($step) {
      self::STARTED => $this->t('What happens next with @domain', $args, $options),
      self::AUTH_CODE => $this->t('The auth code for @domain', $args, $options),
      self::PUSHED => $this->t('@domain is on its way to your account', $args, $options),
      self::TRANSFERRED => $this->t('@domain is yours', $args, $options),
      default => throw new \InvalidArgumentException(sprintf('Unknown handover email "%s".', $step)),
    };
  }

  /**
   * Returns an email's template variables.
   *
   * @return array<string, mixed>
   *   The heading, intro, steps and outro.
   */
  protected function content(HandoverInterface $handover, string $step): array {
    $transfer = $handover->getTransfer();
    $registrar = (string) $handover->getRegistrar()?->label();
    $args = [
      '@domain' => $handover->getDomain(),
      '@registrar' => $registrar,
      '@new_registrar' => (string) $transfer?->registrar,
      '@to' => $this->account($transfer, $registrar),
    ];
    $accept = $registrar
      ? $this->t('If @registrar asks you to accept the domain, accept it in your account.', $args)
      : $this->t('If your registrar asks you to accept the domain, accept it in your account.', $args);
    $yours = $this->t('We email you once the domain is yours.', $args);

    return match ($step) {
      self::STARTED => $this->started($transfer, $registrar, $args, $accept, $yours),
      self::AUTH_CODE => [
        'heading' => $this->t('The auth code for @domain', $args),
        'intro' => [
          $transfer?->registrar
            ? $this->t('Use this code, also called an EPP or transfer code, to transfer @domain to @new_registrar:', $args)
            : $this->t('Use this code, also called an EPP or transfer code, to transfer @domain to your registrar:', $args),
        ],
        'outro' => [
          $this->t('Keep it private: anyone with the code can transfer the domain.', $args),
          $yours,
        ],
      ],
      self::PUSHED => [
        'heading' => $this->t('@domain is on its way to your account', $args),
        'intro' => [$this->t('We pushed @domain to @to.', $args)],
        'outro' => [$accept, $yours],
      ],
      self::TRANSFERRED => [
        'heading' => $this->t('@domain is yours', $args),
        'intro' => [$this->t('The handover of @domain is complete: the domain is in your account now.', $args)],
        'outro' => [
          $this->t('Check its contact details and nameservers at your registrar.', $args),
          $this->t('Thank you for your purchase.', $args),
        ],
      ],
      default => throw new \InvalidArgumentException(sprintf('Unknown handover email "%s".', $step)),
    };
  }

  /**
   * Returns what happens next, for the buyer's answer at checkout.
   */
  protected function started(?Transfer $transfer, string $registrar, array $args, TranslatableMarkup $accept, TranslatableMarkup $yours): array {
    $variables = ['heading' => $this->t('Thank you for buying @domain', $args)];
    if (!$transfer) {
      $variables['intro'] = [$this->t('We need to know where @domain should go.', $args)];
      $variables['outro'] = [
        $registrar
          ? $this->t('Reply to this email with your @registrar account to push it to, or the registrar you want it transferred to.', $args)
          : $this->t("Reply to this email with your account at the domain's registrar to push it to, or the registrar you want it transferred to.", $args),
      ];
      return $variables;
    }
    if ($transfer->method === Transfer::TRANSFER) {
      $variables['intro'] = [$this->t('Here is how @domain moves to @new_registrar.', $args)];
      $variables['steps'] = [
        $this->t('We unlock @domain and email you its auth code, also called an EPP or transfer code.', $args),
        $this->t('Start a transfer at @new_registrar with that code. Most registrars charge for a year of renewal, which is added to the registration.', $args),
        $this->t('Approve the transfer if either registrar emails you about it. Most transfers finish within a week.', $args),
        $yours,
      ];
      $variables['outro'] = [$this->t('If you want the domain to go somewhere else, reply to this email.', $args)];
      return $variables;
    }
    $variables['intro'] = [$this->t('Here is how @domain reaches your account.', $args)];
    $variables['steps'] = [$this->t('We push @domain to @to.', $args), $accept, $yours];
    $variables['outro'] = [$this->t('If those account details are wrong, reply to this email.', $args)];
    return $variables;
  }

  /**
   * Names the buyer's account a push goes to.
   */
  protected function account(?Transfer $transfer, string $registrar): TranslatableMarkup {
    $account = (string) $transfer?->account;
    $args = ['@registrar' => $registrar, '@account' => $account];
    return match (TRUE) {
      $registrar !== '' && $account !== '' => $this->t('your @registrar account, @account', $args),
      $registrar !== '' => $this->t('your @registrar account', $args),
      $account !== '' => $this->t('your account, @account', $args),
      default => $this->t('your account'),
    };
  }

}

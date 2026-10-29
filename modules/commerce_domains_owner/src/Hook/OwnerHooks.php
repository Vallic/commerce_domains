<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Cron and the owner notification mails.
 */
class OwnerHooks {

  use StringTranslationTrait;

  public function __construct(
    protected Renewals $renewals,
    protected Notifier $notifier,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Takes domains left to expire off sale when due, tells the owner about
   * domains gone from a registrar, and sends the day's digest of expiring
   * domains.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->renewals->takeOffSale();
    $this->notifier->registrarChecks();
    $this->notifier->digest();
  }

  /**
   * Implements hook_mail().
   *
   * The owner notifications, plain text. Sites change them with
   * hook_mail_alter().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $options = ['langcode' => $message['langcode']];
    $text = match ($key) {
      Notifier::SOLD => $this->sold($params, $options),
      Notifier::SOLD_ELSEWHERE => $this->soldElsewhere($params, $options),
      Notifier::SYNC_FAILED => $this->syncFailed($params, $options),
      Notifier::EXPIRING => $this->expiring($params, $options),
      Notifier::REGISTRAR_MISSING => $this->registrarMissing($params, $options),
      Notifier::OFFER_EXPIRED => $this->offerExpired($params, $options),
      default => NULL,
    };
    if ($text) {
      [$message['subject'], $paragraphs] = $text;
      $message['body'][] = implode("\n\n", array_map('strval', array_filter($paragraphs, fn($paragraph) => (string) $paragraph !== '')));
    }
  }

  /**
   * Builds the mail about domains sold on the site.
   */
  protected function sold(array $params, array $options): array {
    $lines = [];
    foreach ($params['domains'] as $domain) {
      $args = ['@domain' => $domain['domain'], '@price' => $domain['price'], '@registrar' => $domain['registrar']];
      $line = $domain['price'] !== '' ? $this->t('@domain for @price', $args, $options) : $this->t('@domain', $args, $options);
      $lines[] = $domain['registrar'] !== '' ? $this->t('@line, registered at @registrar', ['@line' => $line] + $args, $options) : $line;
    }
    $first = reset($params['domains']);
    $args = [
      '@domain' => $first['domain'],
      '@order' => $params['order'],
      '@count' => count($params['domains']),
      '@buyer' => $params['buyer'],
      '@url' => $params['url'],
    ];
    $subject = count($params['domains']) > 1
      ? $this->t('Sold: @count domains in order @order', $args, $options)
      : $this->t('Sold: @domain', $args, $options);
    $paragraphs = [
      $this->t('Order @order sold on the site:', $args, $options) . "\n" . self::listing($lines),
      $this->t('Buyer: @buyer', $args, $options),
      $params['transfer'],
      $this->t('The order: @url', $args, $options),
    ];
    return [$subject, $paragraphs];
  }

  /**
   * Builds the mail about a sale a marketplace reported.
   */
  protected function soldElsewhere(array $params, array $options): array {
    $args = [
      '@domain' => $params['domain'],
      '@provider' => $params['provider'],
      '@status' => $params['status'],
      '@url' => $params['url'],
    ];
    $paragraphs = [
      $this->t('@provider reports @domain as @status.', $args, $options),
      $params['url'] !== ''
        ? $this->t("Enter what you received on the domain's Sold for field to see its profit:\n@url", $args, $options)
        : $this->t('It is no longer in the catalog.', $args, $options),
    ];
    return [$this->t('Sold on @provider: @domain', $args, $options), $paragraphs];
  }

  /**
   * Builds the mail about a failing marketplace sync.
   */
  protected function syncFailed(array $params, array $options): array {
    $args = [
      '@provider' => $params['provider'],
      '@error' => $params['whole'],
      '@count' => $params['count'],
      '@more' => $params['more'],
      '@url' => $params['url'],
    ];
    $refused = '';
    if ($params['domains']) {
      $refused = $this->formatPlural($params['count'], '@provider refused 1 domain:', '@provider refused @count domains:', $args, $options)
        . "\n" . self::listing($params['domains']);
    }
    $paragraphs = [
      $params['whole'] !== '' ? $this->t('The sync with @provider failed: @error', $args, $options) : '',
      $refused,
      $params['more'] ? $this->t('And @more more.', $args, $options) : '',
      $this->t("The marketplace listings:\n@url", $args, $options),
      $this->t('This mail comes at most once a day for each marketplace.', [], $options),
    ];
    return [$this->t('@provider sync failed', $args, $options), $paragraphs];
  }

  /**
   * Builds the digest of expiring domains.
   */
  protected function expiring(array $params, array $options): array {
    $args = [
      '@count' => $params['count'],
      '@days' => $params['days'],
      '@more' => $params['more'],
      '@url' => $params['url'],
    ];
    $sections = [
      'decide' => $this->t('Decide whether to keep these:', [], $options),
      'renew' => $this->t("Renew these, or turn on auto-renew: you keep them, and they don't renew automatically:", [], $options),
      'stop' => $this->t('Turn off auto-renew for these: you let them expire, but the registrar renews them.', [], $options),
    ];
    $paragraphs = [];
    foreach ($sections as $key => $heading) {
      $paragraphs[] = !empty($params[$key]) ? $heading . "\n" . self::listing($params[$key]) : '';
    }
    $paragraphs[] = $params['more'] ? $this->t('And @more more.', $args, $options) : '';
    $paragraphs[] = $this->t("Keep them or let them expire:\n@url", $args, $options);
    $subject = $this->formatPlural($params['count'], '1 domain expires within @days days', '@count domains expire within @days days', $args, $options);
    return [$subject, $paragraphs];
  }

  /**
   * Builds the mail about domains a registrar no longer holds.
   */
  protected function registrarMissing(array $params, array $options): array {
    $args = [
      '@provider' => $params['provider'],
      '@date' => $params['date'],
      '@count' => $params['count'],
      '@more' => $params['more'],
      '@url' => $params['url'],
    ];
    $found = $this->t('Reading the @provider account on @date did not find these domains, which the catalog records there:', $args, $options);
    $paragraphs = [
      $found . "\n" . self::listing($params['domains']),
      $params['more'] ? $this->t('And @more more.', $args, $options) : '',
      $this->t('They may have been transferred away or expired, or be recorded at the wrong registrar.', [], $options),
      $this->t("Compare the account with the catalog:\n@url", $args, $options),
    ];
    $subject = $this->formatPlural($params['count'], '@provider no longer holds 1 domain', '@provider no longer holds @count domains', $args, $options);
    return [$subject, $paragraphs];
  }

  /**
   * Builds the mail about an expired offer.
   */
  protected function offerExpired(array $params, array $options): array {
    $args = [
      '@domain' => $params['domain'],
      '@amount' => $params['amount'],
      '@counter' => $params['counter'],
      '@buyer' => $params['buyer'],
      '@url' => $params['url'],
    ];
    $what = match ($params['from']) {
      'accepted' => $this->t("@buyer didn't pay for @domain in time, so the accepted offer expired and its order was cancelled.", $args, $options),
      'countered' => $this->t("@buyer didn't answer your counter-offer of @counter for @domain in time, so it expired.", $args, $options),
      default => $this->t('Nobody answered the offer of @amount from @buyer for @domain in time, so it expired.', $args, $options),
    };
    $paragraphs = [$what, $this->t("The offer:\n@url", $args, $options)];
    return [$this->t('Offer on @domain expired', $args, $options), $paragraphs];
  }

  /**
   * Lists lines as a plain text list.
   */
  protected static function listing(array $lines): string {
    return implode("\n", array_map(fn($line) => '- ' . $line, $lines));
  }

}

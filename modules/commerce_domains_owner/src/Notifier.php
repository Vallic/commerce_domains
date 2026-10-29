<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_offer\Entity\OfferInterface;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tells the owner what needs them.
 *
 * Sales on the site and on marketplaces, failing marketplace syncs, domains
 * expiring soon, domains a registrar no longer holds, and offers that
 * expired. Offer mails the offers module sends already aren't repeated.
 * Everything goes to one address, the store's unless set, and each kind can
 * be turned off. News that repeats is held back: expiring domains come in
 * one digest a day, and a failing sync mails once a day per marketplace.
 * Mails never carry credentials, auth codes or the buyers' offer links.
 */
class Notifier {

  use StringTranslationTrait;

  // The kinds of notification: the keys of the notify setting and the mails.
  public const SOLD = 'sold';

  public const SOLD_ELSEWHERE = 'sold_elsewhere';

  public const SYNC_FAILED = 'sync_failed';

  public const EXPIRING = 'expiring';

  public const REGISTRAR_MISSING = 'registrar_missing';

  public const OFFER_EXPIRED = 'offer_expired';

  // A failing sync mails once in this many seconds per marketplace; queued
  // pushes retry an outage every 15 minutes.
  public const SYNC_FAILED_INTERVAL = 86400;

  // The day the last digest went out, in the site's time zone.
  public const DIGEST_KEY = 'commerce_domains_owner.digest_sent';

  // The last failure of each marketplace sync, keyed by provider ID.
  public const SYNC_FAILURES_KEY = 'commerce_domains_owner.sync_failures';

  // The registrar checks already looked at, keyed by provider ID.
  public const REGISTRAR_CHECKS_KEY = 'commerce_domains_owner.registrar_checks';

  // Domains or errors listed in one mail; the rest are counted.
  protected const MAX_LINES = 50;

  // Products loaded at once for the digest.
  protected const CHUNK_SIZE = 50;

  public function __construct(
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected Renewals $renewals,
    protected RegistrarSync $registrarSync,
  ) {}

  /**
   * Returns the address owner notifications go to.
   *
   * The one set, otherwise the default store's, otherwise the site's.
   */
  public function address(): string {
    $mail = (string) $this->configFactory->get('commerce_domains_owner.settings')->get('notify_mail');
    if ($mail !== '') {
      return $mail;
    }
    /** @var \Drupal\commerce_store\StoreStorageInterface $stores */
    $stores = $this->entityTypeManager->getStorage('commerce_store');
    return (string) $stores->loadDefault()?->getEmail() ?: (string) $this->configFactory->get('system.site')->get('mail');
  }

  /**
   * Whether a kind of notification is turned on.
   */
  public function isOn(string $kind): bool {
    return (bool) $this->configFactory->get('commerce_domains_owner.settings')->get('notify.' . $kind);
  }

  /**
   * Tells the owner about the domains of an order placed on the site.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array<int, array{product: \Drupal\commerce_product\Entity\ProductInterface, price: \Drupal\commerce_price\Price|null}> $sold
   *   Its domains and what each sold for, as SaleRecorder returns them.
   */
  public function sold(OrderInterface $order, array $sold): bool {
    if (!$sold) {
      return FALSE;
    }
    $domains = [];
    foreach ($sold as ['product' => $product, 'price' => $price]) {
      $domains[] = [
        'domain' => $product->label(),
        'price' => $this->money($price),
        'registrar' => $this->registrarName($product),
      ];
    }
    return $this->send(self::SOLD, [
      'domains' => $domains,
      'order' => (string) ($order->getOrderNumber() ?: $order->id()),
      'buyer' => (string) $order->getEmail(),
      'transfer' => $this->transfer($order),
      'url' => $order->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ]);
  }

  /**
   * Tells the owner a marketplace sold a domain.
   */
  public function soldElsewhere(ProviderInterface $provider, string $domain, ?ProductInterface $product, string $status): bool {
    return $this->send(self::SOLD_ELSEWHERE, [
      'domain' => $product ? (string) $product->label() : $domain,
      'provider' => (string) $provider->label(),
      'status' => $status,
      'url' => $product ? $product->toUrl('edit-form', ['absolute' => TRUE])->toString() : '',
    ]);
  }

  /**
   * Records a failed marketplace sync, and tells the owner once a day.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The marketplace.
   * @param array<string, string> $errors
   *   Messages keyed by domain, under '' for a failure of the whole run.
   */
  public function syncFailed(ProviderInterface $provider, array $errors): bool {
    $now = $this->time->getCurrentTime();
    $failures = $this->state->get(self::SYNC_FAILURES_KEY, []);
    $mailed = (int) ($failures[$provider->id()]['mailed'] ?? 0);
    $whole = (string) ($errors[''] ?? '');
    unset($errors['']);

    $sent = FALSE;
    if ($now - $mailed >= self::SYNC_FAILED_INTERVAL) {
      $lines = [];
      foreach (array_slice($errors, 0, self::MAX_LINES, TRUE) as $name => $message) {
        $lines[] = $name . ': ' . $message;
      }
      $sent = $this->send(self::SYNC_FAILED, [
        'provider' => (string) $provider->label(),
        'whole' => $whole,
        'domains' => $lines,
        'count' => count($errors),
        'more' => max(0, count($errors) - self::MAX_LINES),
        'url' => $this->url('view.commerce_domains_listings.page_1') ?: $provider->toUrl('edit-form', ['absolute' => TRUE])->toString(),
      ]);
    }
    $failures[$provider->id()] = [
      'time' => $now,
      'message' => $whole !== '' ? $whole : (string) $this->formatPlural(count($errors), '1 domain refused.', '@count domains refused.'),
      'mailed' => $sent ? $now : $mailed,
    ];
    $this->state->set(self::SYNC_FAILURES_KEY, $failures);
    return $sent;
  }

  /**
   * Returns the last recorded failure of a marketplace sync.
   *
   * @return array{time: int, message: string, mailed: int}|null
   *   When it failed and why; NULL when it never failed.
   */
  public function lastSyncFailure(string $provider_id): ?array {
    return $this->state->get(self::SYNC_FAILURES_KEY, [])[$provider_id] ?? NULL;
  }

  /**
   * Mails the day's digest of the expiring domains that need the owner.
   *
   * Domains not decided yet, domains kept that don't renew automatically,
   * and domains left to expire that do. Once a day, and only when there is
   * one.
   *
   * @return bool
   *   Whether it was sent.
   */
  public function digest(): bool {
    $today = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d');
    if (!$this->isOn(self::EXPIRING) || $this->state->get(self::DIGEST_KEY) === $today || !($query = $this->renewals->query($this->renewals->days()))) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $lines = ['decide' => [], 'renew' => [], 'stop' => []];
    foreach (array_chunk($query->execute(), self::CHUNK_SIZE) as $chunk) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($storage->loadMultiple($chunk) as $product) {
        $decision = $product->hasField('domain_renewal') ? $product->get('domain_renewal')->value : NULL;
        $auto_renew = Renewals::autoRenews($product);
        $group = match (TRUE) {
          $decision === NULL || $decision === '' => 'decide',
          $decision === DomainCosts::KEEP && $auto_renew !== TRUE => 'renew',
          $decision === DomainCosts::EXPIRE && $auto_renew === TRUE => 'stop',
          default => NULL,
        };
        if ($group) {
          $lines[$group][] = $this->expiryLine($product);
        }
      }
      $storage->resetCache($chunk);
    }
    $this->state->set(self::DIGEST_KEY, $today);
    $count = count($lines, COUNT_RECURSIVE) - count($lines);
    if (!$count) {
      return FALSE;
    }
    $params = [
      'days' => $this->renewals->days(),
      'count' => $count,
      'more' => 0,
      'url' => $this->url('commerce_domains_owner.expiring'),
    ];
    foreach ($lines as $group => $group_lines) {
      $params[$group] = array_slice($group_lines, 0, self::MAX_LINES);
      $params['more'] += max(0, count($group_lines) - self::MAX_LINES);
    }
    return $this->send(self::EXPIRING, $params);
  }

  /**
   * Tells the owner about domains a registrar no longer holds.
   *
   * From each registrar's last read of the whole account, the domains that
   * were there at the read before. Sold domains, and domains left to expire
   * whose expiry date has passed, are meant to go and are left out.
   *
   * @return int
   *   How many mails were sent.
   */
  public function registrarChecks(): int {
    $seen = $this->state->get(self::REGISTRAR_CHECKS_KEY, []);
    $changed = FALSE;
    $sent = 0;
    foreach ($this->renewals->registrars() as $id => $provider) {
      $check = $this->registrarSync->lastCheck((string) $id);
      if (!$check || ($seen[$id]['time'] ?? NULL) === $check['time']) {
        continue;
      }
      $new = array_diff($check['missing'], $seen[$id]['missing'] ?? []);
      $seen[$id] = $check;
      $changed = TRUE;
      if ($new = $this->unexpected($provider, $new)) {
        $sent += (int) $this->send(self::REGISTRAR_MISSING, [
          'provider' => (string) $provider->label(),
          'date' => $this->dateFormatter->format($check['time'], 'short'),
          'domains' => array_slice($new, 0, self::MAX_LINES),
          'count' => count($new),
          'more' => max(0, count($new) - self::MAX_LINES),
          'url' => $this->url('entity.commerce_domains_provider.registrations', ['commerce_domains_provider' => $id]),
        ]);
      }
    }
    if ($changed) {
      $this->state->set(self::REGISTRAR_CHECKS_KEY, $seen);
    }
    return $sent;
  }

  /**
   * Takes the registrars' current checks as seen, so only later news mails.
   */
  public function seeRegistrarChecks(): void {
    $seen = [];
    foreach (array_keys($this->renewals->registrars()) as $id) {
      if ($check = $this->registrarSync->lastCheck((string) $id)) {
        $seen[$id] = $check;
      }
    }
    $this->state->set(self::REGISTRAR_CHECKS_KEY, $seen);
  }

  /**
   * Tells the owner an offer expired.
   *
   * @param \Drupal\commerce_domains_offer\Entity\OfferInterface $offer
   *   The offer.
   * @param string $from
   *   The state it expired from: pending, countered or accepted.
   */
  public function offerExpired(OfferInterface $offer, string $from): bool {
    return $this->send(self::OFFER_EXPIRED, [
      'domain' => $offer->getDomain(),
      'from' => $from,
      'amount' => $this->money($offer->getAmount()),
      'counter' => $this->money($offer->getCounterAmount()),
      'buyer' => $offer->getBuyerName() ?: $offer->getEmail(),
      'url' => $offer->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ]);
  }

  /**
   * Formats an amount.
   */
  public function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

  /**
   * Sends a notification, when that kind is on and there is an address.
   */
  protected function send(string $kind, array $params): bool {
    $to = $this->isOn($kind) ? $this->address() : '';
    if ($to === '') {
      return FALSE;
    }
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $message = $this->mailManager->mail('commerce_domains_owner', $kind, $to, $langcode, $params);
    return !empty($message['result']);
  }

  /**
   * Describes an expiring domain in a line of the digest.
   */
  protected function expiryLine(ProductInterface $product): string {
    $expires = (int) $product->get('domain_expires')->value;
    $options = ['langcode' => $this->languageManager->getDefaultLanguage()->getId()];
    $args = [
      '@domain' => $product->label(),
      '@date' => $this->dateFormatter->format($expires, 'custom', 'Y-m-d'),
      '@when' => Renewals::when($expires, $this->time->getRequestTime()),
      '@registrar' => $this->registrarName($product) ?: $this->t('no registrar recorded', [], $options),
      '@auto' => match (Renewals::autoRenews($product)) {
        TRUE => $this->t('auto-renew on', [], $options),
        FALSE => $this->t('auto-renew off', [], $options),
        NULL => $this->t('auto-renew unknown', [], $options),
      },
    ];
    $cost = Costs::price($product, 'domain_renewal_cost');
    return $cost
      ? (string) $this->t('@domain: expires @date (@when) at @registrar, @auto, renews for @cost.', $args + ['@cost' => $this->money($cost)], $options)
      : (string) $this->t('@domain: expires @date (@when) at @registrar, @auto.', $args, $options);
  }

  /**
   * Filters out the missing domains that are meant to be gone.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The registrar.
   * @param string[] $names
   *   The domains its account no longer holds.
   *
   * @return string[]
   *   The ones the owner should look at.
   */
  protected function unexpected(ProviderInterface $provider, array $names): array {
    if (!$names) {
      return [];
    }
    $now = $this->time->getRequestTime();
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties([
      'domain' => array_values($names),
      'domain_registrar' => $provider->id(),
    ]);
    $unexpected = [];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($products as $product) {
      $sold = $product->hasField('domain_sold') && !$product->get('domain_sold')->isEmpty();
      $lapsed = $product->hasField('domain_renewal') && $product->get('domain_renewal')->value === DomainCosts::EXPIRE
        && (int) $product->get('domain_expires')->value <= $now;
      if (!$sold && !$lapsed) {
        $unexpected[(string) $product->get('domain')->value] = TRUE;
      }
    }
    $unexpected = array_keys($unexpected);
    sort($unexpected);
    return $unexpected;
  }

  /**
   * Returns the name of a domain's registrar, or an empty string.
   */
  protected function registrarName(ProductInterface $product): string {
    $registrar = $product->hasField('domain_registrar') ? $product->get('domain_registrar')->entity : NULL;
    return $registrar ? (string) $registrar->label() : '';
  }

  /**
   * Describes where the buyer wants the domain, from the domain checkout.
   */
  protected function transfer(OrderInterface $order): string {
    // Commerce Domains Checkout is optional.
    if (!class_exists(Transfer::class) || !($transfer = Transfer::fromOrder($order))) {
      return '';
    }
    $options = ['langcode' => $this->languageManager->getDefaultLanguage()->getId()];
    if ($transfer->method === Transfer::TRANSFER) {
      return (string) $this->t('Transfer it to @registrar.', ['@registrar' => $transfer->registrar], $options);
    }
    $args = ['@account' => $transfer->account, '@registrar' => (string) Transfer::currentRegistrar($order)];
    return $args['@registrar'] !== ''
      ? (string) $this->t('Push it to the account @account at @registrar.', $args, $options)
      : (string) $this->t('Push it to the account @account at its registrar.', $args, $options);
  }

  /**
   * Returns the absolute URL of a route, or an empty string without it.
   */
  protected function url(string $route, array $parameters = []): string {
    try {
      return Url::fromRoute($route, $parameters, ['absolute' => TRUE])->toString();
    }
    catch (RouteNotFoundException) {
      return '';
    }
  }

}

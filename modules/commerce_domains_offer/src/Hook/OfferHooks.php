<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Hook;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_price\Price;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Commerce Domains Offers.
 */
class OfferHooks {

  use StringTranslationTrait;

  public function __construct(
    protected OfferManager $offerManager,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Expires offers left unanswered or unpaid too long.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->offerManager->expireStale();
  }

  /**
   * Implements hook_mail().
   *
   * The offer emails, plain text. Sites change them with hook_mail_alter().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    $offer = $params['offer'];
    $options = ['langcode' => $message['langcode']];
    $args = [
      '@domain' => $offer->getDomain(),
      '@amount' => $this->money($offer->getAmount()),
      '@counter' => $this->money($offer->getCounterAmount()),
      '@agreed' => $this->money($offer->getAgreedAmount()),
      '@name' => $offer->getBuyerName() ?: $offer->getEmail(),
      '@mail' => $offer->getEmail(),
      '@link' => $params['buyer_url'],
      '@admin_link' => $params['admin_url'],
    ];
    [$subject, $body] = match ($key) {
      'received' => [
        $this->t('Your offer on @domain', $args, $options),
        $this->t("Thank you for your offer of @amount for @domain. We'll answer by email; you can also follow it here:\n\n@link", $args, $options),
      ],
      'new_offer' => [
        $this->t('New offer on @domain', $args, $options),
        $this->t("@name (@mail) offered @amount for @domain.\n\nAnswer it here:\n\n@admin_link", $args, $options),
      ],
      'countered' => [
        $this->t('A counter-offer on @domain', $args, $options),
        $this->t("Thank you for your offer of @amount for @domain. We can't accept it, but we would sell the domain for @counter. Accept or decline the counter-offer here:\n\n@link", $args, $options),
      ],
      'accepted' => [
        $this->t('@domain is yours for @agreed', $args, $options),
        $this->t("We accept @agreed for @domain. Pay here to complete the purchase:\n\n@link", $args, $options),
      ],
      'declined' => [
        $this->t('Your offer on @domain', $args, $options),
        $this->t("Thank you for your offer of @amount for @domain. We can't accept it this time.", $args, $options),
      ],
      'expired' => [
        $this->t('Your offer on @domain has expired', $args, $options),
        $this->t('Your offer of @amount for @domain has expired. You are welcome to make a new one.', $args, $options),
      ],
      'buyer_accepted' => [
        $this->t('Counter-offer on @domain accepted', $args, $options),
        $this->t("@name accepted @agreed for @domain. Their order waits for payment:\n\n@admin_link", $args, $options),
      ],
      'buyer_declined' => [
        $this->t('Counter-offer on @domain declined', $args, $options),
        $this->t("@name declined the counter-offer of @counter for @domain.\n\n@admin_link", $args, $options),
      ],
      default => [NULL, NULL],
    };
    if ($subject !== NULL) {
      $message['subject'] = $subject;
      $message['body'][] = $body;
    }
  }

  /**
   * Formats an amount for an email.
   */
  protected function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

}

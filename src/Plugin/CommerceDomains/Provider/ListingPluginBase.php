<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\SaleTermRules;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;

/**
 * Base class for providers that list domains for sale, by API or by file.
 *
 * Adds the price factors and the pricing rules marketplaces share.
 */
abstract class ListingPluginBase extends ProviderPluginBase {

  /**
   * The provider's own sale terms, where they differ from the general ones.
   *
   * @var array<string, mixed>
   */
  protected array $saleTerms = [];

  /**
   * Sets the provider's own sale terms.
   *
   * @param array<string, mixed> $terms
   *   See SaleTermRules::withOverrides().
   */
  public function setSaleTerms(array $terms): static {
    $this->saleTerms = $terms;
    return $this;
  }

  /**
   * Returns the rules for domains that set no terms of their own.
   */
  public function saleTermRules(): SaleTermRules {
    return SaleTermRules::general()->withOverrides($this->saleTerms);
  }

  /**
   * {@inheritdoc}
   *
   * Plugins drop premium_price_factor when they never list Atom premium
   * names, which also hides it from the form.
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'price_factor' => 1.0,
      'premium_price_factor' => 1.2,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['price_factor'] = [
      '#type' => 'number',
      '#title' => $this->t('Price factor'),
      '#description' => $this->t('Multiplies the catalog price to cover the @provider commission. 1.1 lists a $1,000 domain at $1,100.', ['@provider' => $this->label()]),
      '#default_value' => $this->configuration['price_factor'],
      '#min' => 0.5,
      '#max' => 3,
      '#step' => 0.01,
      '#required' => TRUE,
    ];
    if (array_key_exists('premium_price_factor', $this->defaultConfiguration())) {
      $form['premium_price_factor'] = [
        '#type' => 'number',
        '#title' => $this->t('Atom premium price factor'),
        '#description' => $this->t('Used instead of the price factor for Atom premium names, so they stay priced above their Atom listing.'),
        '#default_value' => $this->configuration['premium_price_factor'],
        '#min' => 0.5,
        '#max' => 3,
        '#step' => 0.01,
        '#required' => TRUE,
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    foreach (['price_factor', 'premium_price_factor'] as $key) {
      if ($form_state->hasValue($key)) {
        $this->configuration[$key] = (float) $form_state->getValue($key);
      }
    }
  }

  /**
   * Returns the factor that turns a domain's catalog price into its price here.
   */
  protected function priceFactor(CatalogItem $item): float {
    if ($item->atomPremium && isset($this->configuration['premium_price_factor'])) {
      return (float) $this->configuration['premium_price_factor'];
    }
    return (float) $this->configuration['price_factor'];
  }

  /**
   * Says what this marketplace does when a domain sets no terms of its own.
   *
   * For the Sale terms page. NULL means it has no such setting.
   *
   * @return array{floor: \Stringable|string|null, minimum_offer: \Stringable|string|null, lease_months: int, note: \Stringable|string|null}
   *   The rule for the floor and the minimum offer, the longest lease it takes
   *   in monthly payments (0 for none), and anything else that applies.
   */
  public function fallbackTerms(): array {
    return [
      'floor' => NULL,
      'minimum_offer' => NULL,
      'lease_months' => 0,
      'note' => $this->t('Sells at the price only.'),
    ];
  }

  /**
   * Returns the usual rule for a minimum offer, for fallbackTerms().
   */
  protected function usualMinimumOffer(): \Stringable {
    $rules = $this->saleTermRules();
    return $this->t('@below% of the price below @break, @above% above', [
      '@below' => $rules->minimumOfferPercent,
      '@above' => $rules->minimumOfferPercentAbove,
      '@break' => number_format($rules->minimumOfferBreak),
    ]);
  }

  /**
   * Returns the usual rule for a floor, for fallbackTerms().
   */
  protected function usualFloor(): \Stringable {
    return $this->t('@percent% of the price', ['@percent' => $this->saleTermRules()->floorPercent]);
  }

  /**
   * Returns the lease-to-own term offered at a price, within a cap.
   *
   * @return int
   *   The term in months, 0 when the price is too low for lease-to-own.
   */
  protected function leaseMonths(float $price, int $cap = 36): int {
    return min($this->saleTermRules()->leaseMonths($price), $cap);
  }

  /**
   * Returns the minimum offer accepted at a price.
   *
   * By the rules here (see saleTermRules()). Marketplaces treat Atom premium
   * names differently, so plugins handle those themselves.
   */
  protected function minimumOffer(float $price): int {
    return (int) $this->saleTermRules()->minimumOffer($price);
  }

  /**
   * Returns whether the domain is sold at all: at its price, or by offers.
   */
  protected function sells(CatalogItem $item): bool {
    return $item->terms->buyNow || $item->terms->offers;
  }

  /**
   * Returns the minimum offer: the domain's own, or the marketplace's rule.
   *
   * @param \Drupal\commerce_domains\CatalogItem $item
   *   The domain.
   * @param int $price
   *   The price here, after the price factor.
   * @param int $rule
   *   The minimum offer the marketplace would take at that price.
   * @param string $currency
   *   The currency the marketplace lists in.
   */
  protected function minimumOfferFor(CatalogItem $item, int $price, int $rule, string $currency = 'USD'): int {
    return $this->termAmount($item, $item->terms->minimumOffer, $price, $currency) ?? $rule;
  }

  /**
   * Returns the floor price: the domain's own, or the marketplace's rule.
   *
   * @param \Drupal\commerce_domains\CatalogItem $item
   *   The domain.
   * @param int $price
   *   The price here, after the price factor.
   * @param int $rule
   *   The floor the marketplace would use at that price.
   * @param string $currency
   *   The currency the marketplace lists in.
   */
  protected function floorPriceFor(CatalogItem $item, int $price, int $rule, string $currency = 'USD'): int {
    return $this->termAmount($item, $item->terms->floorPrice, $price, $currency) ?? $rule;
  }

  /**
   * Returns the lease term: the domain's own, or the rule, within a cap.
   *
   * @return int
   *   The longest lease in monthly payments; 0 for none, as one payment is no
   *   lease.
   */
  protected function leaseMonthsFor(CatalogItem $item, int $rule, int $cap): int {
    $months = min($item->terms->leaseMonths ?? $rule, $cap);
    return $months >= 2 ? $months : 0;
  }

  /**
   * Turns an amount from the domain's terms into whole units here.
   *
   * Raised by the price factor, like the price, and never above the price.
   *
   * @return int|null
   *   The amount, or NULL when the domain sets none or it can't be converted.
   */
  private function termAmount(CatalogItem $item, ?Price $amount, int $price, string $currency): ?int {
    $converted = $amount ? $item->amount($amount, $currency) : NULL;
    return $converted === NULL ? NULL : min((int) ($converted * $this->priceFactor($item)), $price);
  }

  /**
   * Returns the CSV files the provider imports, for CsvExportInterface.
   *
   * @return array<string, string>
   *   Labels keyed by file ID; one file, "listings", unless overridden.
   */
  public function csvFiles(): array {
    return ['listings' => (string) $this->t('Listings')];
  }

  /**
   * Turns a description into one line of plain text for a CSV cell.
   *
   * @param string|null $text
   *   The description, possibly HTML.
   * @param string[] $remove
   *   Characters the marketplace's import can't take.
   */
  protected static function csvText(?string $text, array $remove = []): string {
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', str_replace($remove, '', $text)));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\Form\FormStateInterface;

/**
 * Base class for providers with a marketplace.
 *
 * Adds the price factors and the pricing rules marketplaces share.
 */
abstract class MarketplacePluginBase extends ProviderPluginBase implements MarketplaceInterface {

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
  public function manages(CatalogItem $item): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function changedFields(array $wanted, array $current): array {
    $changed = [];
    foreach ($wanted as $field => $value) {
      if (!array_key_exists($field, $current) || $current[$field] !== $value) {
        $changed[] = $field;
      }
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function describeValue(string $field, mixed $value): string {
    return is_scalar($value) || $value === NULL ? var_export($value, TRUE) : json_encode($value);
  }

  /**
   * {@inheritdoc}
   */
  public function lockReason(array $current): ?string {
    return NULL;
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
   * Returns the lease-to-own term offered at a price.
   *
   * Nothing below 2,000, then 6/12/24/36 months as the price grows.
   *
   * @return int
   *   The term in months, 0 when the price is too low for lease-to-own.
   */
  protected function leaseMonths(float $price, int $cap = 36): int {
    $months = match (TRUE) {
      $price < 2000 => 0,
      $price < 8000 => 6,
      $price < 20000 => 12,
      $price < 50000 => 24,
      default => 36,
    };
    return min($months, $cap);
  }

  /**
   * Returns the minimum offer accepted at a price.
   *
   * Half the price below 10,000, a quarter above. Marketplaces treat Atom
   * premium names differently, so plugins handle those themselves.
   */
  protected function minimumOffer(float $price): int {
    return $price < 10000 ? (int) ($price * 0.5) : (int) ($price * 0.25);
  }

  /**
   * Reads a numeric string as an int when it is whole.
   */
  protected static function number(string|int|float $value): int|float {
    $value = (float) $value;
    return floor($value) == $value ? (int) $value : $value;
  }

  /**
   * Formats a whole amount of money for people reviewing a sync.
   */
  protected function formatMoney(int|float $amount, string $symbol = '$'): string {
    return $symbol . number_format($amount, is_float($amount) && floor($amount) != $amount ? 2 : 0);
  }

}

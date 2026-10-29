<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_pricing;

use Drupal\commerce_price\Price;

/**
 * A pricing rule: the price it gives the domains it matches.
 *
 * Each condition left empty matches any domain.
 */
final class PriceRule {

  public function __construct(
    // The price the rule gives.
    public readonly Price $price,
    // Public suffixes such as "com" or "co.uk", IDNs as A-labels; none for
    // any extension.
    public readonly array $extensions = [],
    // The label length range in characters, the extension not counted.
    public readonly ?int $minLength = NULL,
    public readonly ?int $maxLength = NULL,
    // The word count range; see PriceRules::words().
    public readonly ?int $minWords = NULL,
    public readonly ?int $maxWords = NULL,
    // TRUE for Atom premium names only, FALSE for the others, NULL for
    // either.
    public readonly ?bool $atomPremium = NULL,
  ) {}

  /**
   * Builds a rule from the settings.
   */
  public static function fromConfig(array $values): self {
    $integer = fn(string $key): ?int => isset($values[$key]) && $values[$key] !== '' ? (int) $values[$key] : NULL;
    return new self(
      price: new Price((string) $values['price']['number'], (string) $values['price']['currency_code']),
      extensions: array_values($values['extensions'] ?? []),
      minLength: $integer('min_length'),
      maxLength: $integer('max_length'),
      minWords: $integer('min_words'),
      maxWords: $integer('max_words'),
      atomPremium: isset($values['atom_premium']) ? (bool) $values['atom_premium'] : NULL,
    );
  }

  /**
   * Returns the rule as the settings keep it.
   */
  public function toConfig(): array {
    return [
      'extensions' => $this->extensions,
      'min_length' => $this->minLength,
      'max_length' => $this->maxLength,
      'min_words' => $this->minWords,
      'max_words' => $this->maxWords,
      'atom_premium' => $this->atomPremium,
      'price' => [
        'number' => $this->price->getNumber(),
        'currency_code' => $this->price->getCurrencyCode(),
      ],
    ];
  }

  /**
   * Whether the rule matches a domain.
   *
   * @param string $extension
   *   The domain's public suffix, IDNs as A-labels.
   * @param int $length
   *   The length of its label in characters.
   * @param int $words
   *   The number of words in its label.
   * @param bool $atom_premium
   *   Whether Atom lists it as premium.
   */
  public function matches(string $extension, int $length, int $words, bool $atom_premium): bool {
    return (!$this->extensions || in_array($extension, $this->extensions, TRUE))
      && self::within($length, $this->minLength, $this->maxLength)
      && self::within($words, $this->minWords, $this->maxWords)
      && ($this->atomPremium === NULL || $this->atomPremium === $atom_premium);
  }

  /**
   * Whether a number is in a range whose ends may be open.
   */
  protected static function within(int $number, ?int $min, ?int $max): bool {
    return ($min === NULL || $number >= $min) && ($max === NULL || $number <= $max);
  }

}

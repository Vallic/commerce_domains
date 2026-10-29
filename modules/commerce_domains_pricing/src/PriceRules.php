<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_pricing;

use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Prices domains by the pricing rules: the first rule that matches wins.
 *
 * Rules give domains saved without a price their price, and reprice a
 * selection on request; a domain's own price is never replaced otherwise.
 */
class PriceRules {

  // The pricing settings, which hold the rules.
  public const CONFIG = 'commerce_domains_pricing.settings';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected DomainNameParser $parser,
  ) {}

  /**
   * Returns the price the rules give a domain.
   *
   * @param string $domain
   *   The domain, best as buyers see it (LearnPhpOnline.com): words are
   *   counted from its capitals and hyphens.
   * @param bool $atom_premium
   *   Whether Atom lists the domain as premium.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The price of the first rule that matches, or NULL when none does or
   *   the name isn't a registrable domain.
   */
  public function price(string $domain, bool $atom_premium = FALSE): ?Price {
    return $this->match($domain, $atom_premium)?->price;
  }

  /**
   * Returns the first rule that matches a domain.
   *
   * @param string $domain
   *   The domain, best as buyers see it.
   * @param bool $atom_premium
   *   Whether Atom lists the domain as premium.
   */
  public function match(string $domain, bool $atom_premium = FALSE): ?PriceRule {
    $parsed = $this->parser->parse($domain);
    if (!$parsed) {
      return NULL;
    }
    // Lengths count characters as buyers see them: münchen has 7.
    $label = $parsed->label;
    if (str_starts_with($label, 'xn--') && function_exists('idn_to_utf8')) {
      $label = idn_to_utf8($label, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46) ?: $label;
    }
    // Words come from the name as written, which has the capitals.
    $written = explode('.', trim($domain), 2)[0];
    $words = self::words(stripos($written, 'xn--') === 0 ? $label : $written);

    foreach ($this->rules() as $rule) {
      if ($rule->matches($parsed->extension, mb_strlen($label), $words, $atom_premium)) {
        return $rule;
      }
    }
    return NULL;
  }

  /**
   * Returns the rules, in the order they are tried.
   *
   * @return \Drupal\commerce_domains_pricing\PriceRule[]
   *   The rules.
   */
  public function rules(): array {
    $rules = [];
    foreach ($this->configFactory->get(self::CONFIG)->get('rules') ?? [] as $values) {
      try {
        $rules[] = PriceRule::fromConfig($values);
      }
      catch (\InvalidArgumentException | \TypeError) {
        // A rule without a valid price, as only an edited config file has.
      }
    }
    return $rules;
  }

  /**
   * Counts the words in a domain label.
   *
   * Hyphens separate words, and so do capitals after a lowercase letter or a
   * digit: LearnPhpOnline has three words, AIChat two. A name in lowercase
   * without hyphens is one word.
   */
  public static function words(string $label): int {
    $words = preg_split('/-+|(?<=[\p{Ll}\p{Nd}])(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', $label, -1, PREG_SPLIT_NO_EMPTY);
    return max(1, $words === FALSE ? 1 : count($words));
  }

}

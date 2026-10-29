<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce\Context;
use Drupal\commerce_price\CurrentCurrencyInterface;
use Drupal\commerce_price\Resolver\ChainPriceResolverInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Tokens for a product's price as the visitor sees it.
 *
 * For structured data on domain pages, such as schema.org offers. The
 * domain's own parts have field tokens: [commerce_product:domain],
 * [commerce_product:domain_label] and [commerce_product:domain_extension].
 */
class TokenHooks {

  use StringTranslationTrait;

  public function __construct(
    protected CurrentCurrencyInterface $currentCurrency,
    protected CurrentStoreInterface $currentStore,
    protected ChainPriceResolverInterface $chainPriceResolver,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    return [
      'tokens' => [
        'commerce_product' => [
          'current_currency' => [
            'name' => $this->t('Current currency'),
            'description' => $this->t('The code of the currency the visitor sees prices in.'),
          ],
          'current_price' => [
            'name' => $this->t('Current price'),
            'description' => $this->t("The default variation's price as the visitor sees it, as a number."),
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $product = $data['commerce_product'] ?? NULL;
    if ($type !== 'commerce_product' || !$product instanceof ProductInterface) {
      return [];
    }
    $replacements = [];
    foreach ($tokens as $name => $original) {
      if ($name === 'current_currency') {
        $replacements[$original] = $this->currentCurrency->getCurrency()->getCurrencyCode();
      }
      elseif ($name === 'current_price' && ($variation = $product->getDefaultVariation())) {
        $context = new Context($this->currentUser, $this->currentStore->getStore());
        $price = $this->chainPriceResolver->resolve($variation, '1', $context);
        if ($price) {
          $replacements[$original] = (string) (float) $price->getNumber();
        }
        $bubbleable_metadata->addCacheableDependency($variation);
      }
    }
    return $replacements;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\CatalogItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A marketplace that takes a minimum offer but reports only the price.
 */
#[CommerceDomainsProvider(
  id: 'test_partial_marketplace',
  label: new TranslatableMarkup('Test partial marketplace'),
)]
final class TestPartialMarketplace extends TestMarketplace {

  protected const STATE_PREFIX = 'commerce_domains_test.partial.';

  /**
   * {@inheritdoc}
   */
  public function buildListing(CatalogItem $item): ?array {
    $listing = parent::buildListing($item);
    return $listing === NULL ? NULL : $listing + [
      'minimum_offer' => $this->minimumOfferFor($item, $listing['price'], $this->minimumOffer($listing['price'])),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function changedFields(array $wanted, array $current): array {
    return parent::changedFields(array_intersect_key($wanted, ['price' => TRUE]), $current);
  }

  /**
   * {@inheritdoc}
   */
  public function unreportedFields(array $wanted): array {
    return array_keys(array_diff_key($wanted, ['price' => TRUE]));
  }

}

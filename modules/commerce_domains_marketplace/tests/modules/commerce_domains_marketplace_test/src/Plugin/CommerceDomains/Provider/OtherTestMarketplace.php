<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider\TestMarketplace;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A second test marketplace, its listings in State of its own.
 *
 * The same keys as TestMarketplace, under commerce_domains_marketplace_test.
 */
#[CommerceDomainsProvider(
  id: 'test_marketplace_other',
  label: new TranslatableMarkup('Other test marketplace'),
)]
final class OtherTestMarketplace extends TestMarketplace {

  protected const STATE_PREFIX = 'commerce_domains_marketplace_test.';

}

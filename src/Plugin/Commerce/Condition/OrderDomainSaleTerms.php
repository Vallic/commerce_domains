<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The order variant: matches when any of the order's domains does.
 */
#[CommerceCondition(
  id: 'commerce_domains_order_sale_terms',
  label: new TranslatableMarkup('Sale terms'),
  entity_type: 'commerce_order',
  display_label: new TranslatableMarkup('Order contains domains with sale terms'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderDomainSaleTerms extends OrderItemDomainSaleTerms {}

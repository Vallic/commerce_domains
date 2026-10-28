<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The order variant: matches when any of the order's domains does.
 */
#[CommerceCondition(
  id: 'commerce_domains_order_registrar',
  label: new TranslatableMarkup('Registrar'),
  entity_type: 'commerce_order',
  display_label: new TranslatableMarkup('Order contains domains registered at'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderDomainRegistrar extends OrderItemDomainRegistrar {}

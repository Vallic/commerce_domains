<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The order variant: matches when any of the order's domains does.
 */
#[CommerceCondition(
  id: 'commerce_domains_order_atom_premium',
  label: new TranslatableMarkup('Atom premium'),
  entity_type: 'commerce_order',
  display_label: new TranslatableMarkup('Order contains Atom premium names'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderDomainAtomPremium extends OrderItemDomainAtomPremium {}

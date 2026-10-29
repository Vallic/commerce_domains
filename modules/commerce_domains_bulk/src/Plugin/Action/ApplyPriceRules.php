<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sets the prices of the selected domains from the pricing rules.
 */
#[Action(
  id: 'commerce_domains_apply_price_rules',
  label: new TranslatableMarkup('Apply pricing rules'),
  confirm_form_route_name: 'commerce_domains_bulk.price_rules',
  type: 'commerce_product',
)]
final class ApplyPriceRules extends DomainBulkActionBase {}

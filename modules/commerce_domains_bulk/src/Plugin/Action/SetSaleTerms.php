<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sets the sale terms of the selected domains.
 */
#[Action(
  id: 'commerce_domains_sale_terms',
  label: new TranslatableMarkup('Set sale terms'),
  confirm_form_route_name: 'commerce_domains_bulk.sale_terms',
  type: 'commerce_product',
)]
final class SetSaleTerms extends DomainBulkActionBase {}

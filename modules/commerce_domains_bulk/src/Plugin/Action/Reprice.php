<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Multiplies the prices of the selected domains by a factor.
 */
#[Action(
  id: 'commerce_domains_reprice',
  label: new TranslatableMarkup('Reprice by a factor'),
  confirm_form_route_name: 'commerce_domains_bulk.reprice',
  type: 'commerce_product',
)]
final class Reprice extends DomainBulkActionBase {}

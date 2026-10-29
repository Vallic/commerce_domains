<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Chooses the marketplaces that list the selected domains.
 */
#[Action(
  id: 'commerce_domains_marketplaces',
  label: new TranslatableMarkup('Choose marketplaces'),
  confirm_form_route_name: 'commerce_domains_bulk.marketplaces',
  type: 'commerce_product',
)]
final class ChooseMarketplaces extends DomainBulkActionBase {}

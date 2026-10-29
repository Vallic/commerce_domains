<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Hook;

use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps what domains cost and sold for to the owner.
 *
 * Buyers, and staff without the portfolio permissions, don't get the costs
 * fields: not on the product form, nor through Views or JSON:API. Runs for
 * every field access check, so the class needs no services.
 */
class FieldAccessHooks {

  /**
   * Implements hook_entity_field_access().
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($field_definition->getTargetEntityTypeId() !== 'commerce_product' || !in_array($field_definition->getName(), DomainCosts::FIELDS, TRUE)) {
      return AccessResult::neutral();
    }
    $granted = $account->hasPermission('manage domain portfolio')
      || ($operation === 'view' && $account->hasPermission('view domain portfolio'));
    return AccessResult::forbiddenIf(!$granted)->cachePerPermissions();
  }

}

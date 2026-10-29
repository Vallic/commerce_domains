<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Lets people who follow handovers see them; changing them is the seller's.
 *
 * Handovers change only through HandoverManager, on the action forms; there
 * are no edit or delete forms.
 */
final class HandoverAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermissions($account, ['view domain handovers', 'hand over domains'], 'OR');
    }
    return AccessResult::allowedIfHasPermission($account, 'hand over domains');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'hand over domains');
  }

}

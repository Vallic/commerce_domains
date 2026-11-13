<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gives the selected domains the records of a DNS template.
 */
#[Action(
  id: 'commerce_domains_dns_template',
  label: new TranslatableMarkup('Apply a DNS template'),
  confirm_form_route_name: 'commerce_domains_bulk.dns_template',
  type: 'commerce_product',
)]
final class ApplyDnsTemplate extends DomainBulkActionBase {

  /**
   * The current user, who uses the action unless another is given.
   */
  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $result = AccessResult::allowedIfHasPermission($account, 'manage domains at registrars')
      ->andIf(parent::access($object, $account, TRUE));
    return $return_as_object ? $result : $result->isAllowed();
  }

}

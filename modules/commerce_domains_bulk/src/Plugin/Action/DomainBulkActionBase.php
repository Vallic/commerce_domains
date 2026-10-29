<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Plugin\Action;

use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for domain actions that ask for their settings on a form.
 *
 * As with core's delete action, the action keeps the selection, and the
 * form it leads to changes the domains.
 *
 * @phpstan-consistent-constructor
 */
abstract class DomainBulkActionBase extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('tempstore.private'));
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities): void {
    $ids = [];
    foreach ($entities as $entity) {
      $ids[] = (int) $entity->id();
    }
    $this->tempStoreFactory->get(BulkEditor::TEMPSTORE)->set($this->getPluginId(), array_values(array_unique($ids)));
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL): void {
    if ($object) {
      $this->executeMultiple([$object]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object instanceof ProductInterface ? BulkEditor::access($object, $account) : AccessResult::forbidden();
    return $return_as_object ? $result : $result->isAllowed();
  }

}

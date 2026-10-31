<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\ProductTabs;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits the fields of one domain tab (see ProductTabs).
 *
 * The form operation names the tab. Only the tab's fields are built and
 * saved, so the rest of the domain stays as it is.
 */
class DomainTabForm extends ContentEntityForm {

  /**
   * The domain tabs.
   *
   * @var \Drupal\commerce_domains\ProductTabs
   */
  protected ProductTabs $productTabs;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->productTabs = $container->get(ProductTabs::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The display keeps only the tab's fields.
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);
    $fields = $this->productTabs->tab($this->getOperation())['fields'] ?? [];
    $display = $this->getFormDisplay($form_state);
    foreach (array_keys($display->getComponents()) as $name) {
      if (!in_array($name, $fields, TRUE)) {
        $display->removeComponent($name);
      }
    }
    $this->setFormDisplay($display, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    unset($actions['delete']);
    if (!empty($this->productTabs->tab($this->getOperation())['read_only'])) {
      $actions['submit']['#access'] = FALSE;
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved %domain.', ['%domain' => $this->entity->label()]));
    return $result;
  }

  /**
   * Returns the page title: the domain.
   */
  public static function title(ProductInterface $commerce_product): string {
    return (string) $commerce_product->label();
  }

  /**
   * Allows a tab on domains whose fields the user may see.
   *
   * A tab that edits needs edit access to one of its fields, and to the
   * domain; a read-only tab needs view access to one.
   */
  public static function access(ProductInterface $commerce_product, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $operation = (string) $route_match->getRouteObject()?->getDefault('_entity_form');
    return self::tabAccess(substr($operation, strlen('commerce_product.')), $commerce_product, $account);
  }

  /**
   * Allows a tab, by its operation; see access().
   */
  public static function tabAccess(string $operation, ProductInterface $commerce_product, AccountInterface $account): AccessResultInterface {
    $tab = \Drupal::service(ProductTabs::class)->tab($operation);
    if (!$tab || !$commerce_product->hasField('domain')) {
      return AccessResult::forbidden()->addCacheableDependency($commerce_product);
    }
    $field_access = $tab['read_only'] ? 'view' : 'edit';
    $allowed = FALSE;
    foreach ($tab['fields'] as $field) {
      if ($commerce_product->hasField($field) && $commerce_product->get($field)->access($field_access, $account)) {
        $allowed = TRUE;
        break;
      }
    }
    $result = AccessResult::allowedIf($allowed)->cachePerPermissions()->addCacheableDependency($commerce_product);
    return $tab['read_only'] ? $result : $result->andIf($commerce_product->access('update', $account, TRUE));
  }

}

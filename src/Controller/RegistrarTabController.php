<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Controller;

use Drupal\commerce_domains\Form\DomainRegistrarForm;
use Drupal\commerce_domains\Form\DomainTabForm;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;

/**
 * A domain's Registrar tab: where it is registered, and the account there.
 *
 * The registrar, expiry and registration date are edited on top (the
 * domain_registrar tab of ProductTabs); below, for registrars whose API
 * manages domains, the domain's state there and what can be changed.
 */
final class RegistrarTabController extends ControllerBase {

  // The domain tab holding the registrar's fields.
  public const TAB = 'domain_registrar';

  /**
   * Builds the page.
   */
  public function page(ProductInterface $commerce_product): array {
    $build = [];
    $account = $this->currentUser();
    if (DomainTabForm::tabAccess(self::TAB, $commerce_product, $account)->isAllowed()) {
      $build['fields'] = $this->entityFormBuilder()->getForm($commerce_product, self::TAB);
    }
    if (self::manages($commerce_product, $account)) {
      $build['account'] = $this->formBuilder()->getForm(DomainRegistrarForm::class, $commerce_product);
    }
    return $build;
  }

  /**
   * Allows the tab when either part is allowed.
   */
  public static function access(ProductInterface $commerce_product, AccountInterface $account): AccessResultInterface {
    $fields = DomainTabForm::tabAccess(self::TAB, $commerce_product, $account);
    $manages = AccessResult::allowedIf(self::manages($commerce_product, $account))->cachePerPermissions();
    return $fields->orIf($manages->andIf(DomainRegistrarForm::access($commerce_product)));
  }

  /**
   * Whether the account panel shows: a managing registrar, and the right.
   */
  protected static function manages(ProductInterface $product, AccountInterface $account): bool {
    return $account->hasPermission('manage domains at registrars') && DomainRegistrarForm::access($product)->isAllowed();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Controller;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Refreshes a provider's registrations at once, from the provider list.
 *
 * The same as Refresh now on the Registrations tab, and as cron: the whole
 * account is read and what changed is written to the catalog.
 */
final class RegistrationRefreshController extends ControllerBase {

  public function __construct(
    protected RegistrarSync $sync,
  ) {}

  /**
   * Allows enabled providers with a registrar API.
   */
  public static function access(ProviderInterface $commerce_domains_provider): AccessResultInterface {
    return AccessResult::allowedIf($commerce_domains_provider->status() && $commerce_domains_provider->hasCapability(RegistrarInterface::class))
      ->addCacheableDependency($commerce_domains_provider);
  }

  /**
   * Refreshes the registrations, then goes back to the destination.
   */
  public function refresh(ProviderInterface $commerce_domains_provider): RedirectResponse {
    // Admin routes load config entities without overrides, and credentials
    // are overrides: load it again with them.
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    $provider = $this->entityTypeManager()->getStorage('commerce_domains_provider')->load($commerce_domains_provider->id());
    $args = ['@label' => $provider->label()];
    try {
      $updated = $this->sync->refresh($provider);
      $this->messenger()->addStatus($this->formatPlural($updated, 'Refreshed @label: 1 product updated.', 'Refreshed @label: @count products updated.', $args));
    }
    catch (ProviderException $e) {
      $this->messenger()->addError($this->t('@label could not be read: @error', $args + ['@error' => $e->getMessage()]));
    }
    // The destination query parameter, when there is one, wins.
    return $this->redirect('entity.commerce_domains_provider.collection');
  }

}

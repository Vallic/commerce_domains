<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import\Hook;

use Drupal\commerce_domains\RegistrarPlan;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Hook implementations for Commerce Domains Import.
 */
class ImportHooks {

  use StringTranslationTrait;

  // The menu link of the domain overview, from Commerce Domains Marketplace.
  protected const OVERVIEW_LINK = 'views_view:views.commerce_domains_overview.page_1';

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * Puts the import under Commerce → Domains when the domain overview is
   * there; otherwise it stays under Commerce as "Import domains".
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    if (isset($links[self::OVERVIEW_LINK], $links['commerce_domains_import.import'])) {
      $links['commerce_domains_import.import']['parent'] = self::OVERVIEW_LINK;
      $links['commerce_domains_import.import']['title'] = new TranslatableMarkup('Import');
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the provider's Registrations tab.
   *
   * The comparison lists the account's domains the catalog lacks; this links
   * to importing them.
   */
  #[Hook('form_commerce_domains_provider_registrations_alter')]
  public function registrationsFormAlter(array &$form, FormStateInterface $form_state): void {
    $plan = $form_state->get('plan');
    if (!isset($form['plan']) || !$plan instanceof RegistrarPlan || !$plan->unknown || !$this->currentUser->hasPermission('import domains')) {
      return;
    }
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($form_state->get('provider'));
    // The import reads enabled providers only.
    if (!$provider || !$provider->status()) {
      return;
    }
    $form['plan']['import'] = [
      '#type' => 'link',
      '#title' => $this->formatPlural(count($plan->unknown), 'Import the domain the catalog lacks', 'Import the @count domains the catalog lacks'),
      '#url' => Url::fromRoute('commerce_domains_import.import', [], ['query' => ['provider' => $provider->id()]]),
      '#attributes' => ['class' => ['button']],
      '#weight' => 5,
    ];
  }

}

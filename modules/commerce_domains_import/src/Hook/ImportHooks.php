<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import\Hook;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains_import\AccountImport;
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
    protected AccountImport $accountImport,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Adds the new domains of registrar accounts set to, once a day.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->accountImport->cron();
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the provider form.
   *
   * Beside the daily registration refresh: also add the account's new
   * domains to the catalog.
   */
  #[Hook('form_commerce_domains_provider_form_alter')]
  public function providerFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['registration_sync'])) {
      return;
    }
    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    $provider = $form_object->getEntity();
    $form['add_new_domains'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also add the domains the account holds that the catalog lacks'),
      '#description' => $this->t("Once a day, as unpublished domains not listed on marketplaces, with the account's expiry and registration dates and auto-renew where it gives them, and the price of the first matching pricing rule, else 0. Publish them, and set their price, when you are ready to sell."),
      '#default_value' => AccountImport::addsNew($provider),
      '#weight' => ($form['registration_sync']['#weight'] ?? 0) + 0.01,
      '#states' => ['visible' => [':input[name="registration_sync"]' => ['checked' => TRUE]]],
    ];
    $form['#entity_builders'][] = [static::class, 'buildProvider'];
  }

  /**
   * Entity builder for the provider form: stores whether to add new domains.
   */
  public static function buildProvider(string $entity_type_id, ProviderInterface $provider, array &$form, FormStateInterface $form_state): void {
    if ($form_state->hasValue('add_new_domains')) {
      $provider->setThirdPartySetting(AccountImport::MODULE, 'add_new', (bool) $form_state->getValue('add_new_domains') && (bool) $form_state->getValue('registration_sync'));
    }
  }

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
      $links['commerce_domains_import.import']['weight'] = 40;
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

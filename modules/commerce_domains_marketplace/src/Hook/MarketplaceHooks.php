<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Hook;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Commerce Domains Marketplace.
 */
class MarketplaceHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_form_FORM_ID_alter() for commerce_domains_provider_form.
   *
   * Adds the sync settings to providers with a marketplace.
   */
  #[Hook('form_commerce_domains_provider_form_alter')]
  public function providerFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    $provider = $form_object->getEntity();
    if (!$provider->hasCapability(MarketplaceInterface::class)) {
      return;
    }

    $form['marketplace'] = [
      '#type' => 'details',
      '#title' => $this->t('Marketplace sync'),
      '#open' => TRUE,
      '#weight' => 5,
    ];
    $form['marketplace']['auto_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Push catalog changes automatically'),
      '#description' => $this->t('Domains that are added, repriced, sold or removed are queued and pushed on the next cron run. Run a dry-run sync with Drush before turning this on.'),
      '#default_value' => MarketplaceSettings::isAutoSync($provider),
    ];
    $form['marketplace']['delete_unlisted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove listings for domains that are no longer for sale'),
      '#description' => $this->t('Only catalog domains are removed. Listings for names the catalog does not know are left alone.'),
      '#default_value' => MarketplaceSettings::deletesUnlisted($provider),
    ];
    $form['#entity_builders'][] = [static::class, 'buildProvider'];
  }

  /**
   * Entity builder for the provider form: stores the sync settings.
   */
  public static function buildProvider(string $entity_type_id, ProviderInterface $provider, array &$form, FormStateInterface $form_state): void {
    foreach (['auto_sync', 'delete_unlisted'] as $key) {
      if ($form_state->hasValue($key)) {
        $provider->setThirdPartySetting(MarketplaceSettings::MODULE, $key, (bool) $form_state->getValue($key));
      }
    }
  }

}

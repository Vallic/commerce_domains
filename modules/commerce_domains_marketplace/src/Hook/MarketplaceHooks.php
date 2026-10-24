<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Hook;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Commerce Domains Marketplace.
 */
class MarketplaceHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

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
    if ($this->entityTypeManager->hasDefinition('commerce_pricelist')) {
      $form['marketplace']['price_list'] = [
        '#type' => 'select',
        '#title' => $this->t('Price list'),
        '#description' => $this->t("Its prices win for their currency; other currencies are converted with Commerce Exchanger when it is installed. The list needn't apply to a store: a disabled list keeps these prices off the storefront."),
        '#options' => $this->priceListOptions(),
        '#empty_option' => $this->t('- None: the variation price -'),
        '#default_value' => MarketplaceSettings::priceList($provider) ?? '',
      ];
    }
    $form['#entity_builders'][] = [static::class, 'buildProvider'];
  }

  /**
   * Returns the price lists for product variations, keyed by UUID.
   *
   * @return array<string, string>
   *   Price list names, marked when disabled.
   */
  protected function priceListOptions(): array {
    $options = [];
    $lists = $this->entityTypeManager->getStorage('commerce_pricelist')->loadByProperties(['type' => 'commerce_product_variation']);
    /** @var \Drupal\commerce_pricelist\Entity\PriceListInterface $list */
    foreach ($lists as $list) {
      $name = (string) $list->getName();
      $options[(string) $list->uuid()] = $list->isEnabled() ? $name : (string) $this->t('@name (disabled)', ['@name' => $name]);
    }
    asort($options);
    return $options;
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
    if ($form_state->hasValue('price_list')) {
      $uuid = (string) $form_state->getValue('price_list');
      if ($uuid === '') {
        $provider->unsetThirdPartySetting(MarketplaceSettings::MODULE, 'price_list');
      }
      else {
        $provider->setThirdPartySetting(MarketplaceSettings::MODULE, 'price_list', $uuid);
      }
    }
  }

}

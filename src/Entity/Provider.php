<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Entity;

use Drupal\commerce_domains\Form\ProviderForm;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginInterface;
use Drupal\commerce_domains\ProviderListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Plugin\DefaultSingleLazyPluginCollection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the domain provider entity type.
 */
#[ConfigEntityType(
  id: 'commerce_domains_provider',
  label: new TranslatableMarkup('Domain provider'),
  label_collection: new TranslatableMarkup('Domain providers'),
  label_singular: new TranslatableMarkup('domain provider'),
  label_plural: new TranslatableMarkup('domain providers'),
  config_prefix: 'provider',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => ProviderListBuilder::class,
    'form' => [
      'add' => ProviderForm::class,
      'edit' => ProviderForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/commerce/domains/settings/providers',
    'add-form' => '/admin/commerce/domains/settings/providers/add',
    'edit-form' => '/admin/commerce/domains/settings/providers/{commerce_domains_provider}',
    'delete-form' => '/admin/commerce/domains/settings/providers/{commerce_domains_provider}/delete',
    'registrations' => '/admin/commerce/domains/settings/providers/{commerce_domains_provider}/registrations',
  ],
  admin_permission: 'administer commerce_domains providers',
  label_count: [
    'singular' => '@count domain provider',
    'plural' => '@count domain providers',
  ],
  config_export: [
    'id',
    'label',
    'plugin',
    'configuration',
    'credential_keys',
    'registration_sync',
    'marketplace',
  ],
)]
class Provider extends ConfigEntityBase implements ProviderInterface {

  /**
   * The provider ID.
   *
   * @var string|null
   */
  protected ?string $id = NULL;

  /**
   * The company's name.
   *
   * @var string|null
   */
  protected ?string $label = NULL;

  /**
   * The API plugin ID, or NULL for a provider without an API.
   *
   * @var string|null
   */
  protected ?string $plugin = NULL;

  /**
   * The plugin's settings.
   *
   * @var array
   */
  protected array $configuration = [];

  /**
   * The keys (Key module) the plugin reads credentials from, by setting.
   *
   * @var array<string, string>
   */
  protected array $credential_keys = [];

  /**
   * Whether cron refreshes registration details daily.
   *
   * @var bool
   */
  protected bool $registration_sync = FALSE;

  /**
   * Whether domains are listed for sale here, by hand when there is no API.
   *
   * @var bool
   */
  protected bool $marketplace = FALSE;

  /**
   * The collection holding the plugin.
   *
   * @var \Drupal\Core\Plugin\DefaultSingleLazyPluginCollection|null
   */
  protected ?DefaultSingleLazyPluginCollection $pluginCollection = NULL;

  /**
   * {@inheritdoc}
   */
  public function getPlugin(): ?ProviderPluginInterface {
    if (!$this->plugin) {
      return NULL;
    }
    $plugin = $this->getPluginCollection()->get($this->plugin);
    assert($plugin instanceof ProviderPluginInterface);
    // The plugin fills the credentials settings.php leaves empty from these.
    return $plugin->setCredentialKeys($this->credential_keys);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): ?string {
    return $this->plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginId(?string $plugin_id): static {
    $this->plugin = $plugin_id ?: NULL;
    $this->configuration = [];
    $this->credential_keys = [];
    $this->pluginCollection = NULL;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCapability(string $capability): bool {
    return $this->getPlugin() instanceof $capability;
  }

  /**
   * {@inheritdoc}
   */
  public function isMarketplace(): bool {
    return $this->marketplace || $this->listsByApi();
  }

  /**
   * {@inheritdoc}
   */
  public function setMarketplace(bool $marketplace): static {
    $this->marketplace = $marketplace;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function listsByApi(): bool {
    return $this->hasCapability(MarketplaceInterface::class) || $this->hasCapability(CsvExportInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentialKeys(): array {
    return $this->credential_keys;
  }

  /**
   * {@inheritdoc}
   */
  public function setCredentialKeys(array $keys): static {
    // Form values for "no key" are empty strings.
    $keys = array_filter(array_map('strval', $keys), fn(string $id) => $id !== '');
    ksort($keys);
    $this->credential_keys = $keys;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function syncsRegistrations(): bool {
    return $this->registration_sync;
  }

  /**
   * {@inheritdoc}
   */
  public function setRegistrationSync(bool $sync): static {
    $this->registration_sync = $sync;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections(): array {
    return $this->plugin ? ['configuration' => $this->getPluginCollection()] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();
    // The chosen keys are config the provider needs: imports bring them
    // along, and deleting one takes it off the provider (see below).
    if ($this->credential_keys && $this->entityTypeManager()->hasDefinition('key')) {
      foreach ($this->entityTypeManager()->getStorage('key')->loadMultiple(array_values($this->credential_keys)) as $key) {
        $this->addDependency('config', $key->getConfigDependencyName());
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $changed = parent::onDependencyRemoval($dependencies);
    $removed = [];
    foreach ($dependencies['config'] ?? [] as $dependency) {
      if ($dependency instanceof ConfigEntityInterface && $dependency->getEntityTypeId() === 'key') {
        $removed[] = $dependency->id();
      }
    }
    $keys = in_array('key', $dependencies['module'] ?? [], TRUE) ? [] : array_diff($this->credential_keys, $removed);
    if ($keys !== $this->credential_keys) {
      // Losing a key leaves the provider in place, waiting for settings.php
      // or another key, rather than deleting it with the key.
      $this->credential_keys = $keys;
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Returns the lazy collection that holds the single configured plugin.
   */
  protected function getPluginCollection(): DefaultSingleLazyPluginCollection {
    if (!$this->pluginCollection) {
      $this->pluginCollection = new DefaultSingleLazyPluginCollection(\Drupal::service('plugin.manager.commerce_domains_provider'), $this->plugin, $this->configuration);
    }
    return $this->pluginCollection;
  }

}

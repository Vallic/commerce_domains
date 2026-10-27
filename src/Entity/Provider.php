<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Entity;

use Drupal\commerce_domains\Form\ProviderForm;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginInterface;
use Drupal\commerce_domains\ProviderListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
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
    'collection' => '/admin/commerce/config/domain-providers',
    'add-form' => '/admin/commerce/config/domain-providers/add',
    'edit-form' => '/admin/commerce/config/domain-providers/{commerce_domains_provider}',
    'delete-form' => '/admin/commerce/config/domain-providers/{commerce_domains_provider}/delete',
    'registrations' => '/admin/commerce/config/domain-providers/{commerce_domains_provider}/registrations',
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
    'registration_sync',
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
   * Whether cron refreshes registration details daily.
   *
   * @var bool
   */
  protected bool $registration_sync = FALSE;

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
    return $this->plugin ? $this->getPluginCollection()->get($this->plugin) : NULL;
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
   * Returns the lazy collection that holds the single configured plugin.
   */
  protected function getPluginCollection(): DefaultSingleLazyPluginCollection {
    if (!$this->pluginCollection) {
      $this->pluginCollection = new DefaultSingleLazyPluginCollection(\Drupal::service('plugin.manager.commerce_domains_provider'), $this->plugin, $this->configuration);
    }
    return $this->pluginCollection;
  }

}

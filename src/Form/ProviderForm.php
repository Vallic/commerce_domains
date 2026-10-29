<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderPluginManager;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for domain providers.
 */
class ProviderForm extends EntityForm {

  /**
   * The entity being edited.
   *
   * @var \Drupal\commerce_domains\Entity\ProviderInterface
   */
  protected $entity;

  /**
   * The provider plugin manager.
   *
   * @var \Drupal\commerce_domains\ProviderPluginManager
   */
  protected ProviderPluginManager $pluginManager;

  /**
   * The typed config manager, for the credentials' names.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected TypedConfigManagerInterface $typedConfig;

  /**
   * The key repository, when the Key module is installed.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected ?KeyRepositoryInterface $keyRepository = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->pluginManager = $container->get('plugin.manager.commerce_domains_provider');
    $instance->typedConfig = $container->get('config.typed');
    if ($container->has('key.repository')) {
      $instance->keyRepository = $container->get('key.repository');
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    // The Ajax rebuild after picking an API runs before afterBuild() copies
    // the value to the entity, so apply it here or its settings won't show.
    if ($this->entity->isNew() && $form_state->hasValue('plugin') && $form_state->getValue('plugin') !== (string) $this->entity->getPluginId()) {
      $this->entity->setPluginId($form_state->getValue('plugin') ?: NULL);
    }

    $form['#prefix'] = '<div id="commerce-domains-provider-form">';
    $form['#suffix'] = '</div>';

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('The company, as buyers and admins know it, e.g. Dynadot.'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => [Provider::class, 'load'],
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $options = array_map(fn(array $definition) => $definition['label'], $this->pluginManager->getDefinitions());
    asort($options);
    $form['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('API'),
      '#description' => $this->t('Leave empty for a registrar that is only recorded on domains.'),
      '#options' => $options,
      '#default_value' => $this->entity->getPluginId(),
      '#empty_option' => $this->t('- None -'),
      '#disabled' => !$this->entity->isNew(),
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'wrapper' => 'commerce-domains-provider-form',
      ],
    ];

    if ($plugin = $this->entity->getPlugin()) {
      $form['status'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#description' => $this->t('Disabled providers are never synced; a Drush dry run still reads them.'),
        '#default_value' => $this->entity->status(),
      ];
      if ($plugin instanceof RegistrarInterface) {
        $form['registration_sync'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Refresh registration details daily'),
          '#description' => $this->t('Cron records this registrar on every catalog domain its account holds, and updates their expiry dates.'),
          '#default_value' => $this->entity->syncsRegistrations(),
        ];
      }
      $form['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('@provider settings', ['@provider' => $plugin->label()]),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      $subform_state = SubformState::createForSubform($form['configuration'], $form, $form_state);
      $form['configuration'] = $plugin->buildConfigurationForm($form['configuration'], $subform_state);
      if ($credentials = $this->buildCredentials($plugin)) {
        $form['configuration'] = self::insertAfter($form['configuration'], 'credentials', ['credential_keys' => $credentials]);
      }
    }

    return $form;
  }

  /**
   * Rebuilds the form after an API is picked.
   */
  public function ajaxRefresh(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (isset($form['configuration']) && ($plugin = $this->entity->getPlugin())) {
      $plugin->validateConfigurationForm($form['configuration'], SubformState::createForSubform($form['configuration'], $form, $form_state));
    }
    if (!$this->keyRepository) {
      return;
    }
    foreach (array_filter((array) $form_state->getValue('credential_keys', [])) as $setting => $key_id) {
      $key = $this->keyRepository->getKey($key_id);
      // Config is exported, so a key kept there would put the secret in
      // every export.
      if ($key && ProviderPluginBase::keyStoresValueInConfig($key)) {
        $form_state->setErrorByName('credential_keys][' . $setting, $this->t('Key %key keeps its value in configuration, which is exported with the site, so it cannot hold an API credential. Choose a key whose provider reads an environment variable or a file.', ['%key' => $key->label()]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $entity */
    if ($entity->isNew() && $form_state->hasValue('plugin') && $form_state->getValue('plugin') !== (string) $entity->getPluginId()) {
      $entity->setPluginId($form_state->getValue('plugin') ?: NULL);
    }
    foreach (['label', 'id', 'status', 'registration_sync'] as $key) {
      if ($form_state->hasValue($key)) {
        $entity->set($key, $form_state->getValue($key));
      }
    }
    // Only there with the Key module, which builds the choices.
    if ($form_state->hasValue('credential_keys')) {
      $entity->setCredentialKeys((array) $form_state->getValue('credential_keys'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    if (isset($form['configuration']) && ($plugin = $this->entity->getPlugin())) {
      $plugin->submitConfigurationForm($form['configuration'], SubformState::createForSubform($form['configuration'], $form, $form_state));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === \SAVED_NEW
      ? $this->t('Created domain provider %label.', $message_args)
      : $this->t('Updated domain provider %label.', $message_args));
    // Syncs of an enabled provider fail without credentials: say so now. The
    // error names where each was looked for, never a value.
    $runtime = $this->runtimeProvider();
    if ($runtime && $runtime->status() && ($error = $runtime->getPlugin()?->getSetupError())) {
      $this->messenger()->addWarning($error);
    }
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Builds, for each credential, where it comes from and the key to use.
   *
   * The key choices need the Key module; without it, only where each
   * credential comes from is shown.
   */
  protected function buildCredentials(ProviderPluginInterface $plugin): array {
    $sources = $this->credentialSources($plugin);
    if (!$sources || (!$this->keyRepository && $this->entity->isNew())) {
      return [];
    }
    $element = [
      '#type' => 'container',
      // The keys are kept on the provider, outside the plugin's settings.
      '#parents' => ['credential_keys'],
      '#tree' => TRUE,
    ];
    if ($this->keyRepository) {
      $element['#type'] = 'fieldset';
      $element['#title'] = $this->t('Keys');
      $element['#description'] = $this->t('Credentials that settings.php does not set can come from keys: <a href=":add">add a key</a> whose provider reads an environment variable or a file, then choose it here. A value from settings.php wins over a key. Keys that keep their value in configuration are refused, since configuration is exported.', [
        ':add' => Url::fromRoute('entity.key.add_form')->toString(),
      ]);
    }
    $chosen = $this->entity->getCredentialKeys();
    foreach ($sources as $setting => $source) {
      $element[$setting] = [
        '#type' => $this->keyRepository ? 'key_select' : 'item',
        '#title' => $this->credentialTitle($plugin, $setting, $source['variable']),
        '#description' => $this->entity->isNew() ? $this->t('Leave empty when settings.php sets it.') : $this->describeSource($source),
      ];
      if ($this->keyRepository) {
        $element[$setting] += [
          '#key_description' => FALSE,
          '#empty_option' => $this->t('- None -'),
          '#empty_value' => '',
          '#default_value' => $chosen[$setting] ?? '',
        ];
      }
    }
    return $element;
  }

  /**
   * Returns where each credential comes from when the provider is used.
   *
   * Admin pages load providers without config overrides, but settings.php
   * sets credentials as overrides, so the saved provider is loaded again
   * with them. A value settings.php sets gets the source 'override'; one
   * saved in the provider's config keeps 'settings'.
   *
   * @return array<string, array>
   *   The plugin's credential sources, keyed by setting.
   */
  protected function credentialSources(ProviderPluginInterface $plugin): array {
    $runtime = $this->runtimeProvider();
    $runtime_plugin = $runtime && $runtime->getPluginId() === $plugin->getPluginId() ? $runtime->getPlugin() : NULL;
    if (!$runtime || !$runtime_plugin) {
      return $plugin->getCredentialSources();
    }
    $config = $this->configFactory()->get($runtime->getConfigDependencyName());
    $sources = $runtime_plugin->getCredentialSources();
    foreach ($sources as $setting => $source) {
      if ($source['source'] === 'settings' && $config->hasOverrides('configuration.' . $setting)) {
        $sources[$setting]['source'] = 'override';
      }
    }
    return $sources;
  }

  /**
   * Returns the saved provider as syncs load it: with config overrides.
   *
   * It is only read, never saved: saving it would write what settings.php
   * sets into config.
   */
  protected function runtimeProvider(): ?ProviderInterface {
    if ($this->entity->isNew()) {
      return NULL;
    }
    $provider = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($this->entity->id());
    return $provider instanceof ProviderInterface ? $provider : NULL;
  }

  /**
   * Says where a credential comes from, never its value.
   *
   * @param array $source
   *   The credential's entry in credentialSources().
   */
  protected function describeSource(array $source): TranslatableMarkup {
    $args = ['%key' => $source['key_label'] ?? $source['key'] ?? ''];
    return match (TRUE) {
      $source['source'] === 'override' && $source['key'] !== NULL => $this->t('In use: the value from settings.php, which wins over the key.'),
      $source['source'] === 'override' => $this->t('In use: the value from settings.php.'),
      $source['source'] === 'settings' => $this->t("In use: a value saved in the provider's configuration, which is exported with it. Move it to settings.php or a key."),
      $source['source'] === 'key' => $this->t('In use: key %key.', $args),
      $source['problem'] === 'key_module' => $this->t('Not in use: key %key needs the Key module.', $args),
      $source['problem'] === 'key_missing' => $this->t('Not in use: key %key does not exist.', $args),
      $source['problem'] === 'key_config' => $this->t('Not in use: key %key keeps its value in configuration. Choose a key whose provider reads an environment variable or a file.', $args),
      $source['problem'] === 'key_error' => $this->t('Not in use: key %key could not be read.', $args),
      $source['problem'] === 'key_empty' => $this->t('Not in use: key %key is empty.', $args),
      !$source['required'] => $this->t('Optional; not set.'),
      $this->keyRepository !== NULL => $this->t('Missing: set it in settings.php, or choose a key.'),
      default => $this->t('Missing: set it in settings.php.'),
    };
  }

  /**
   * Returns a credential's name, from the plugin's config schema.
   */
  protected function credentialTitle(ProviderPluginInterface $plugin, string $setting, string $variable): TranslatableMarkup|string {
    $definition = $this->typedConfig->getDefinition('commerce_domains.provider_plugin.' . $plugin->getPluginId());
    $label = $definition['mapping'][$setting]['label'] ?? NULL;
    return is_string($label) && $label !== ''
      ? $this->t('@label (@variable)', ['@label' => $label, '@variable' => $variable])
      : $variable;
  }

  /**
   * Inserts elements after the one with the given key, or first.
   */
  protected static function insertAfter(array $form, string $after, array $elements): array {
    if (!isset($form[$after])) {
      return $elements + $form;
    }
    $result = [];
    foreach ($form as $key => $value) {
      $result[$key] = $value;
      if ($key === $after) {
        $result += $elements;
      }
    }
    return $result;
  }

}

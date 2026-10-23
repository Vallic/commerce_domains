<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\ProviderPluginManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->pluginManager = $container->get('plugin.manager.commerce_domains_provider');
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
        '#description' => $this->t('Disabled providers are never called, not even by a manual sync.'),
        '#default_value' => $this->entity->status(),
      ];
      $form['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('@provider settings', ['@provider' => $plugin->label()]),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      $subform_state = SubformState::createForSubform($form['configuration'], $form, $form_state);
      $form['configuration'] = $plugin->buildConfigurationForm($form['configuration'], $subform_state);
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
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $entity */
    if ($entity->isNew() && $form_state->hasValue('plugin') && $form_state->getValue('plugin') !== (string) $entity->getPluginId()) {
      $entity->setPluginId($form_state->getValue('plugin') ?: NULL);
    }
    foreach (['label', 'id', 'status'] as $key) {
      if ($form_state->hasValue($key)) {
        $entity->set($key, $form_state->getValue($key));
      }
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
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}

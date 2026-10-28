<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Commerce\Condition;

use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Matches domains registered at some registrars.
 *
 * @phpstan-consistent-constructor
 */
#[CommerceCondition(
  id: 'commerce_domains_order_item_registrar',
  label: new TranslatableMarkup('Registrar'),
  entity_type: 'commerce_order_item',
  display_label: new TranslatableMarkup('Domain registered at'),
  category: new TranslatableMarkup('Domains'),
)]
class OrderItemDomainRegistrar extends DomainConditionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager, for the registrars on the form.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['registrars' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $options = [];
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      if ($provider->hasCapability(RegistrarInterface::class)) {
        $options[$provider->id()] = $provider->label();
      }
    }
    $form['registrars'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Registrars'),
      '#options' => $options,
      '#default_value' => $this->configuration['registrars'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['registrars'] = array_values(array_filter($values['registrars']));
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    foreach ($this->configuration['registrars'] as $id) {
      $dependencies['config'][] = 'commerce_domains.provider.' . $id;
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  protected function matches(ProductVariationInterface $variation): bool {
    $product = $variation->getProduct();
    $registrar = $product->hasField('domain_registrar') ? $product->get('domain_registrar')->target_id : NULL;
    return $registrar !== NULL && in_array($registrar, $this->configuration['registrars'], TRUE);
  }

}

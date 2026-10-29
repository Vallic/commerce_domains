<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Form;

use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses the marketplaces that list the selected domains.
 */
final class MarketplacesForm extends BulkFormBase {

  /**
   * The selection plugins, for the marketplaces the sale terms offer.
   */
  protected SelectionPluginManagerInterface $selectionManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->selectionManager = $container->get('plugin.manager.entity_reference_selection');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actionId(): string {
    return 'commerce_domains_marketplaces';
  }

  /**
   * {@inheritdoc}
   */
  protected function edit(): string {
    return BulkEditor::MARKETPLACES;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->products), 'Choose the marketplaces for 1 domain?', 'Choose the marketplaces for @count domains?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The next marketplace sync adds or removes their listings, and CSV files follow. Domains whose variations have no sale terms are left as they are.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Save marketplaces');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Marketplaces'),
      '#options' => [
        'all' => $this->t('List on all marketplaces'),
        'none' => $this->t('Keep off all marketplaces: sell on this site only'),
        'except' => $this->t('List on all marketplaces except these'),
      ],
      '#default_value' => 'all',
    ];
    $form['providers'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Keep off'),
      '#title_display' => 'invisible',
      '#options' => $this->marketplaces(),
      '#states' => [
        'visible' => [':input[name="mode"]' => ['value' => 'except']],
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('mode') === 'except' && !array_filter($form_state->getValue('providers') ?: [])) {
      $form_state->setErrorByName('providers', $this->t('Choose the marketplaces to keep the domains off.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function options(FormStateInterface $form_state): array {
    return [
      'mode' => (string) $form_state->getValue('mode'),
      'providers' => array_values(array_filter($form_state->getValue('providers') ?: [])),
    ];
  }

  /**
   * Returns the providers that list domains, as "Not listed on" offers them.
   *
   * @return array<string, string>
   *   Provider labels keyed by ID.
   */
  protected function marketplaces(): array {
    $selection = $this->selectionManager->getInstance([
      'target_type' => 'commerce_domains_provider',
      'handler' => 'commerce_domains_listing:provider',
    ]);
    $options = [];
    foreach ($selection instanceof SelectionInterface ? $selection->getReferenceableEntities() : [] as $labels) {
      $options += $labels;
    }
    return array_map('strval', $options);
  }

}

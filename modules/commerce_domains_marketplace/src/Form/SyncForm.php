<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks or syncs whole marketplaces from the browser, like Drush does.
 *
 * Each marketplace runs in its own batch step. Checking reads the
 * marketplace and records its listings, pushing nothing; a sale it finds
 * still takes the domain off sale here, as the hourly check does. Syncing
 * also pushes what differs.
 */
final class SyncForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_marketplace_sync';
  }

  /**
   * Returns the enabled marketplaces with an API that is set up.
   *
   * @return array<string, \Drupal\commerce_domains\Entity\ProviderInterface>
   *   Providers keyed by ID.
   */
  protected function providers(): array {
    $providers = [];
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
      if ($provider->status() && $provider->hasCapability(MarketplaceInterface::class)) {
        $providers[(string) $id] = $provider;
      }
    }
    return $providers;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('<em>Check</em> reads each marketplace and records its listings, changing nothing there. <em>Sync</em> also pushes the catalog: new listings, changed prices and terms, and removals. A sale either finds takes the domain off sale here.') . '</p>',
    ];

    $options = [];
    foreach ($this->providers() as $id => $provider) {
      $checked = $this->state->get(SyncScheduler::CHECKED_KEY . $id);
      $options[$id] = [
        'label' => $provider->label(),
        'auto' => MarketplaceSettings::isAutoSync($provider) ? $this->t('Automatic') : $this->t('By hand'),
        'checked' => $checked ? $this->dateFormatter->format((int) $checked, 'short') : $this->t('Never'),
        'problem' => (string) $provider->getPlugin()->getSetupError(),
      ];
    }
    $form['providers'] = [
      '#type' => 'tableselect',
      '#header' => [
        'label' => $this->t('Marketplace'),
        'auto' => $this->t('Pushes'),
        'checked' => $this->t('Last hourly check'),
        'problem' => $this->t('Setup'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No enabled marketplace has an API. Enable one on the domain providers page.'),
      '#default_value' => array_fill_keys(array_keys(array_filter($options, fn(array $row) => $row['problem'] === '')), TRUE),
    ];
    foreach ($options as $id => $row) {
      if ($row['problem'] !== '') {
        $form['providers'][$id]['#disabled'] = TRUE;
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['check'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check'),
      '#mode' => 'check',
      '#access' => (bool) $options,
    ];
    $form['actions']['sync'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync'),
      '#mode' => 'sync',
      '#button_type' => 'primary',
      '#access' => (bool) $options,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!array_filter((array) $form_state->getValue('providers'))) {
      $form_state->setErrorByName('providers', $this->t('Choose at least one marketplace.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) $form_state->getTriggeringElement()['#mode'];
    $batch = (new BatchBuilder())
      ->setTitle($mode === 'sync' ? $this->t('Syncing marketplaces') : $this->t('Checking marketplaces'))
      ->setFinishCallback([self::class, 'finished']);
    $providers = $this->providers();
    foreach (array_keys(array_filter((array) $form_state->getValue('providers'))) as $id) {
      if (isset($providers[$id])) {
        $batch->addOperation([self::class, 'run'], [(string) $id, $mode === 'sync']);
      }
    }
    batch_set($batch->toArray());
  }

  /**
   * Batch operation: checks or syncs one marketplace.
   */
  public static function run(string $provider_id, bool $push, array &$context): void {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = \Drupal::entityTypeManager()->getStorage('commerce_domains_provider')->load($provider_id);
    if (!$provider instanceof ProviderInterface) {
      return;
    }
    $sync = \Drupal::service(MarketplaceSync::class);
    $args = ['@provider' => $provider->label()];
    try {
      $plan = $sync->plan($provider);
      $errors = $push && !$plan->isEmpty() ? $sync->apply($provider, $plan) : [];
    }
    catch (ProviderException $e) {
      $sync->reportFailure($provider, ['' => $e->getMessage()]);
      $context['results']['errors'][] = new TranslatableMarkup('@provider: @error', $args + ['@error' => $e->getMessage()]);
      return;
    }

    $args += [
      '@create' => count(array_diff_key($plan->create, $errors)),
      '@update' => count(array_diff_key($plan->update, $errors)),
      '@delete' => MarketplaceSettings::deletesUnlisted($provider) ? count(array_diff($plan->delete, array_keys($errors))) : 0,
      '@unchanged' => $plan->unchanged,
      '@failed' => count($errors),
    ];
    $context['results']['done'][] = $push
      ? new TranslatableMarkup('@provider: @create added, @update updated, @delete removed, @unchanged unchanged, @failed failed.', $args)
      : new TranslatableMarkup('@provider: @create to add, @update to update, @delete to remove, @unchanged unchanged.', $args);
    foreach ($plan->newSales as $name) {
      $context['results']['sales'][] = new TranslatableMarkup('@provider shows @domain sold: it is now off sale here.', $args + ['@domain' => $name]);
    }
    foreach ($errors as $name => $error) {
      $context['results']['errors'][] = new TranslatableMarkup('@provider refused @domain: @error', $args + [
        '@domain' => $name,
        '@error' => $error,
      ]);
    }
    $context['message'] = new TranslatableMarkup('@provider done.', $args);
  }

  /**
   * Batch finish callback: reports each marketplace.
   */
  public static function finished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The run stopped before every marketplace was done.'));
    }
    foreach ($results['done'] ?? [] as $message) {
      $messenger->addStatus($message);
    }
    foreach ($results['sales'] ?? [] as $message) {
      $messenger->addWarning($message);
    }
    foreach ($results['errors'] ?? [] as $message) {
      $messenger->addError($message);
    }
  }

}

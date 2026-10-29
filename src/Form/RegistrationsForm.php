<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Compares a registrar account with the catalog, and applies the result.
 *
 * The same as `drush commerce-domains:registrar-sync`. The account is read
 * when asked, never on page load, and the comparison is applied as it was
 * shown.
 */
final class RegistrationsForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RegistrarSync $sync,
    protected StateInterface $stateStore,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(RegistrarSync::class),
      $container->get('state'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_provider_registrations';
  }

  /**
   * Allows the page for providers with a registrar API.
   */
  public static function access(ProviderInterface $commerce_domains_provider): AccessResultInterface {
    return AccessResult::allowedIf($commerce_domains_provider->hasCapability(RegistrarInterface::class))
      ->addCacheableDependency($commerce_domains_provider);
  }

  /**
   * The page title.
   */
  public function title(ProviderInterface $commerce_domains_provider): string {
    return (string) $this->t('@label registrations', ['@label' => $commerce_domains_provider->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProviderInterface $commerce_domains_provider = NULL): array {
    // Admin routes load config entities without overrides, and credentials
    // are overrides: load it again with them.
    $form_state->set('provider', $commerce_domains_provider->id());
    $provider = $this->provider($form_state);

    $last = (int) $this->stateStore->get('commerce_domains.registration_sync.' . $provider->id(), 0);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t("Every catalog domain the @label account holds is recorded at @label and gets the account's expiry date. Domains recorded at @label that the account no longer has are only listed. Nothing is written to @label.", ['@label' => $provider->label()]) . '</p>',
    ];
    $form['refresh'] = [
      '#markup' => '<p>' . ($provider->syncsRegistrations()
        ? $this->t('Cron refreshes this daily; last run: @time.', ['@time' => $last ? $this->dateFormatter->format($last) : $this->t('never')])
        : $this->t('Cron does not refresh this provider; turn on <em>Refresh registration details daily</em> in its settings.')) . '</p>',
    ];

    if ($error = $provider->getPlugin()->getSetupError()) {
      $this->messenger()->addWarning($error);
      return $form;
    }

    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Only these domains'),
      '#description' => $this->t('One per line or comma-separated. Leave empty to compare the whole account.'),
      '#rows' => 2,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['compare'] = [
      '#type' => 'submit',
      '#value' => $this->t('Compare with the account'),
      '#submit' => ['::compare'],
    ];

    $plan = $form_state->get('plan');
    if ($plan instanceof RegistrarPlan) {
      $form['plan'] = $this->buildPlan($provider, $plan);
      $form['actions']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Apply'),
        '#button_type' => 'primary',
        '#access' => !$plan->isEmpty(),
        '#disabled' => !$provider->status(),
      ];
      if (!$provider->status() && !$plan->isEmpty()) {
        $form['actions']['apply']['#suffix'] = $this->t('@label is disabled; enable it to apply.', ['@label' => $provider->label()]);
      }
    }
    return $form;
  }

  /**
   * Builds the comparison.
   */
  protected function buildPlan(ProviderInterface $provider, RegistrarPlan $plan): array {
    $args = ['@label' => $provider->label(), '@count' => count($plan->registrations)];
    $build['summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('@count domains in the @label account.', $args),
      '#header' => [$this->t('Catalog domains'), $this->t('Count'), $this->t('Applying')],
      '#rows' => [
        [$this->t('Expiry to update'), count($plan->expires), $this->t('updates the expiry date')],
        [
          $this->t('In the account, recorded elsewhere'),
          count($plan->elsewhere),
          $this->t('records @label as the registrar', $args),
        ],
        [$this->t('Up to date'), $plan->unchanged, ''],
        [
          $this->t('Not in the account'),
          count($plan->missing),
          $this->t('nothing; transferred away, expired, or recorded wrongly'),
        ],
        [$this->t('In the account, not in the catalog'), count($plan->unknown), $this->t('nothing')],
      ],
    ];
    // Only products that keep it have auto-renew, e.g. with the costs trait
    // of Commerce Domains Owner.
    if ($plan->autoRenew) {
      $row = [
        $this->t('Auto-renew to update'),
        count($plan->autoRenew),
        $this->t('records whether it renews automatically'),
      ];
      array_splice($build['summary']['#rows'], 1, 0, [$row]);
    }

    $date = fn(?int $time) => $time === NULL ? '-' : gmdate('Y-m-d', $time);
    $rows = [];
    foreach ($plan->elsewhere as $name => $registrar) {
      $recorded = $this->t('recorded at @registrar', ['@registrar' => $registrar ?? $this->t('no registrar')]);
      $rows[] = [$name, $recorded, $date($plan->registrations[$name]->expires)];
    }
    foreach ($plan->expires as $name => [$old, $new]) {
      $rows[] = [$name, $this->t('expiry'), $date($old) . ' → ' . $date($new)];
    }
    $renews = fn(?bool $value) => match ($value) {
      TRUE => $this->t('on'),
      FALSE => $this->t('off'),
      NULL => $this->t('unknown'),
    };
    foreach ($plan->autoRenew as $name => [$old, $new]) {
      $change = $this->t('auto-renew: @old → @new', ['@old' => $renews($old), '@new' => $renews($new)]);
      $rows[] = [$name, $change, $date($plan->registrations[$name]->expires)];
    }
    foreach ($plan->missing as $name) {
      $rows[] = [$name, $this->t('not in the account'), ''];
    }
    if ($rows) {
      $build['details'] = [
        '#type' => 'details',
        '#title' => $this->t('Domains (@count)', ['@count' => count($rows)]),
        '#open' => count($rows) <= 50,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Domain'), $this->t('Change'), $this->t('Expires')],
          '#rows' => $rows,
        ],
      ];
    }
    return $build;
  }

  /**
   * Reads the account and keeps the comparison for the rebuilt form.
   */
  public function compare(array &$form, FormStateInterface $form_state): void {
    $provider = $this->provider($form_state);
    $text = trim((string) $form_state->getValue('domains'));
    $names = $text === '' ? NULL : array_values(array_unique(array_map(DomainName::normalize(...), preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY))));
    try {
      $form_state->set('plan', $this->sync->plan($provider, $names));
    }
    catch (ProviderException $e) {
      $form_state->set('plan', NULL);
      $args = ['@label' => $provider->label(), '@error' => $e->getMessage()];
      $this->messenger()->addError($this->t('@label could not be read: @error', $args));
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $provider = $this->provider($form_state);
    $plan = $form_state->get('plan');
    if (!$plan instanceof RegistrarPlan) {
      return;
    }
    try {
      $updated = $this->sync->apply($provider, $plan);
      $args = ['@count' => $updated, '@label' => $provider->label()];
      $this->messenger()->addStatus($this->t('Updated @count products from @label.', $args));
    }
    catch (ProviderException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

  /**
   * Loads the form's provider afresh, with its overrides.
   */
  protected function provider(FormStateInterface $form_state): ProviderInterface {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface */
    return $this->entityTypeManager->getStorage('commerce_domains_provider')->load($form_state->get('provider'));
  }

}

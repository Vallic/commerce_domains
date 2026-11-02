<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\RenewalCostDefaults;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for running the portfolio: notifications and renewals.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Fills the default renewal costs from a price list.
   *
   * @var \Drupal\commerce_domains_owner\RenewalCostDefaults
   */
  protected RenewalCostDefaults $renewalCostDefaults;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->renewalCostDefaults = $container->get(RenewalCostDefaults::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_owner_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_domains_owner.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\commerce_store\StoreStorageInterface $stores */
    $stores = $this->entityTypeManager->getStorage('commerce_store');
    $store_mail = (string) $stores->loadDefault()?->getEmail();
    $form['notify_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Send owner notifications to'),
      '#description' => $store_mail !== ''
        ? $this->t("Leave empty for the store's email address, @mail.", ['@mail' => $store_mail])
        : $this->t("Leave empty for the store's email address."),
      '#config_target' => 'commerce_domains_owner.settings:notify_mail',
    ];
    $form['notify'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Notifications'),
      '#description' => $this->t('Offers have their own mails, set on the domain offers settings; they come to this address too, unless those settings name another.'),
    ];
    $kinds = [
      Notifier::SOLD => [
        $this->t('A domain sold on the site'),
        $this->t('With the order, and where the buyer wants the domain.'),
      ],
      Notifier::SOLD_ELSEWHERE => [
        $this->t('A marketplace sold a domain'),
        $this->t('When a marketplace sync finds a listing sold.'),
      ],
      Notifier::SYNC_FAILED => [
        $this->t('A marketplace sync failed'),
        $this->t('At most once a day for each marketplace.'),
      ],
      Notifier::EXPIRING => [
        $this->t('Domains expire soon'),
        $this->t('A daily digest of the domains not decided yet, those kept that do not renew automatically, and those left to expire that do.'),
      ],
      Notifier::REGISTRAR_MISSING => [
        $this->t('A registrar no longer holds a domain'),
        $this->t('When reading the whole account no longer finds a domain recorded there, unless it sold or you let it expire.'),
      ],
      Notifier::OFFER_EXPIRED => [
        $this->t('An offer expired'),
        $this->t('Unanswered, or accepted and not paid in time.'),
      ],
    ];
    foreach ($kinds as $kind => [$title, $description]) {
      $form['notify'][$kind] = [
        '#type' => 'checkbox',
        '#title' => $title,
        '#description' => $description,
        '#config_target' => 'commerce_domains_owner.settings:notify.' . $kind,
      ];
    }
    $form['expiring_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Expiring soon: within this many days'),
      '#description' => $this->t('For the Expiring soon page, the dashboard and the daily digest.'),
      '#min' => 1,
      '#max' => 3650,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains_owner.settings:expiring_days',
    ];
    $form['off_sale_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Take a domain you let expire off sale this many days before its expiry date'),
      '#description' => $this->t('Leave time for a sale to complete before the domain lapses. 0 keeps it on sale until its expiry date.'),
      '#min' => 0,
      '#max' => 3650,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains_owner.settings:off_sale_days',
    ];
    $form['appraisals_per_day'] = [
      '#type' => 'number',
      '#title' => $this->t('Appraise this many domains a day'),
      '#description' => $this->t("Cron appraises domains never appraised first, then redoes appraisals older than 90 days, up to this many a day. Stay under the appraisal service's daily limit, e.g. Atom's. 0 turns appraisals off."),
      '#min' => 0,
      '#max' => 10000,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains_owner.settings:appraisals_per_day',
    ];
    $lines = array_map(
      fn(array $cost) => $cost['tld'] . ' ' . $cost['number'] . ' ' . $cost['currency_code'],
      (array) $this->config('commerce_domains_owner.settings')->get('renewal_costs'),
    );
    $form['renewal_costs'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default yearly renewal costs'),
      '#description' => $this->t("One extension a line, with what a year's renewal costs: <code>com 10.99 USD</code>, <code>co.uk 7 GBP</code>. A domain whose renewals aren't recorded takes its extension's, for profit, expenses, the Expiring soon page and the digest."),
      '#default_value' => implode("\n", $lines),
      '#rows' => 6,
      '#placeholder' => "com 10.99 USD\nnet 12.50 USD\nio 39 USD",
    ];
    $registrars = $this->registrars();
    $by_registrar = [];
    foreach ((array) $this->config('commerce_domains_owner.settings')->get('provider_renewal_costs') as $cost) {
      $by_registrar[$cost['provider']][] = $cost['tld'] . ' ' . $cost['number'] . ' ' . $cost['currency_code'];
    }
    $form['provider_renewal_costs'] = [
      '#type' => 'details',
      '#title' => $this->t('By registrar'),
      '#description' => $this->t("What a registrar charges, where it differs from the costs above, in the same form. A domain takes its registrar's cost for its extension first."),
      '#open' => (bool) $by_registrar,
      '#tree' => TRUE,
      '#access' => (bool) $registrars,
    ];
    foreach ($registrars as $id => $registrar) {
      $form['provider_renewal_costs'][$id] = [
        '#type' => 'textarea',
        '#title' => $registrar->label(),
        '#default_value' => implode("\n", $by_registrar[$id] ?? []),
        '#rows' => 3,
      ];
    }
    $form['renewal_fill'] = [
      '#type' => 'details',
      '#title' => $this->t("Fill in from Porkbun's prices"),
      '#description' => $this->t("Adds what a year's renewal costs at Porkbun, in US dollars, for each extension of your domains that has no cost here yet. Porkbun publishes its prices; they are one registrar's, so change any that you pay differently."),
    ];
    $form['renewal_fill']['renewal_replace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace the costs already set for those extensions'),
    ];
    $form['renewal_fill']['fill'] = [
      '#type' => 'submit',
      '#value' => $this->t("Fill in from Porkbun's prices"),
      '#submit' => ['::fillRenewalCosts'],
      '#limit_validation_errors' => [['renewal_replace']],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $form_state->set('renewal_costs', $this->parseCosts((string) $form_state->getValue('renewal_costs'), 'renewal_costs', $form_state));
    $by_registrar = [];
    foreach ((array) $form_state->getValue('provider_renewal_costs') as $id => $value) {
      foreach ($this->parseCosts((string) $value, 'provider_renewal_costs][' . $id, $form_state) as $cost) {
        $by_registrar[] = ['provider' => (string) $id] + $cost;
      }
    }
    $form_state->set('provider_renewal_costs', $by_registrar);
  }

  /**
   * Reads costs, one extension a line, and flags the lines that aren't.
   *
   * @return array<int, array{tld: string, number: string, currency_code: string}>
   *   The costs, by extension.
   */
  protected function parseCosts(string $value, string $element, FormStateInterface $form_state): array {
    $costs = [];
    $currencies = $this->entityTypeManager->getStorage('commerce_currency');
    foreach (preg_split('/\R/', $value) as $number => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $args = ['@line' => $number + 1, '%text' => $line];
      if (!preg_match('/^\.?([a-z0-9-]+(?:\.[a-z0-9-]+)*)\s+(\d+(?:[.,]\d{1,2})?)\s+([A-Za-z]{3})$/i', $line, $match)) {
        $form_state->setErrorByName($element, $this->t('Line @line, %text: write the extension, the amount and the currency, e.g. com 10.99 USD.', $args));
        continue;
      }
      $currency = strtoupper($match[3]);
      if (!$currencies->load($currency)) {
        $form_state->setErrorByName($element, $this->t('Line @line, %text: the currency is not on the site.', $args));
        continue;
      }
      $tld = strtolower($match[1]);
      $costs[$tld] = ['tld' => $tld, 'number' => str_replace(',', '.', $match[2]), 'currency_code' => $currency];
    }
    ksort($costs);
    return array_values($costs);
  }

  /**
   * Returns the providers that hold domains.
   *
   * @return \Drupal\commerce_domains\Entity\ProviderInterface[]
   *   Keyed by ID.
   */
  protected function registrars(): array {
    return array_filter(
      $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple(),
      fn($provider) => $provider instanceof ProviderInterface && $provider->hasCapability(RegistrarInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $this->config('commerce_domains_owner.settings')
      ->set('renewal_costs', $form_state->get('renewal_costs') ?? [])
      ->set('provider_renewal_costs', $form_state->get('provider_renewal_costs') ?? [])
      ->save();
  }

  /**
   * Fills in the default renewal costs from Porkbun's prices.
   */
  public function fillRenewalCosts(array &$form, FormStateInterface $form_state): void {
    if (!$this->entityTypeManager->getStorage('commerce_currency')->load(RenewalCostDefaults::CURRENCY)) {
      $this->messenger()->addError($this->t("Porkbun's prices are in US dollars: add the currency to the site first."));
      return;
    }
    try {
      $fill = $this->renewalCostDefaults->fromPorkbun((bool) $form_state->getValue('renewal_replace'));
    }
    catch (ProviderException $e) {
      $this->messenger()->addError($this->t("Porkbun's prices could not be read: @error", ['@error' => $e->getMessage()]));
      return;
    }
    $this->renewalCostDefaults->save($fill['costs']);
    $changed = array_keys($fill['added'] + $fill['replaced']);
    $this->messenger()->addStatus($changed
      ? $this->formatPlural(count($changed), 'Filled in the renewal cost of 1 extension: @list.', 'Filled in the renewal costs of @count extensions: @list.', ['@list' => implode(', ', $changed)])
      : $this->t('Every extension of your domains already has a renewal cost.'));
    if ($fill['unpriced']) {
      $this->messenger()->addWarning($this->t('Porkbun does not sell @list: enter their costs yourself.', ['@list' => implode(', ', $fill['unpriced'])]));
    }
  }

}

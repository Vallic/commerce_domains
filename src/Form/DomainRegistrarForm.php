<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manages a domain at its registrar: nameservers, lock, renewal, auth code.
 *
 * The page reads the domain from the registrar each time it is shown, and
 * every button acts on the registrar at once. Each change is logged with who
 * made it; an auth code is shown on the page only, never in a message or the
 * log.
 */
final class DomainRegistrarForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('logger.channel.commerce_domains'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_domain_registrar';
  }

  /**
   * Returns the domain's registrar, when its API can manage domains.
   */
  public static function provider(ProductInterface $product): ?ProviderInterface {
    if (!$product->hasField('domain') || !$product->hasField('domain_registrar') || $product->get('domain')->isEmpty()) {
      return NULL;
    }
    $provider = $product->get('domain_registrar')->entity;
    return $provider instanceof ProviderInterface && $provider->hasCapability(DomainManagementInterface::class) ? $provider : NULL;
  }

  /**
   * Allows the page for domains at a registrar whose API can manage them.
   */
  public static function access(ProductInterface $commerce_product): AccessResultInterface {
    $provider = self::provider($commerce_product);
    $result = AccessResult::allowedIf($provider !== NULL)->addCacheableDependency($commerce_product);
    return $provider ? $result->addCacheableDependency($provider) : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProductInterface $commerce_product = NULL): array {
    $product = $commerce_product;
    $provider = self::provider($product);
    $name = (string) $product->get('domain')->value;
    $form_state->set('product', $product->id());
    $label = $provider->label();

    $plugin = $provider->getPlugin();
    assert($plugin instanceof DomainManagementInterface);
    if ($error = $plugin->getSetupError()) {
      $this->messenger()->addWarning($error);
      return $form;
    }
    if (!$provider->status()) {
      $this->messenger()->addWarning($this->t('@label is disabled; enable it to manage domains there.', ['@label' => $label]));
      return $form;
    }

    try {
      $registration = $plugin->fetchRegistrations([$name])[$name] ?? NULL;
      $nameservers = $registration ? $plugin->getNameservers($name) : [];
    }
    catch (ProviderException $e) {
      $args = ['@label' => $label, '@error' => $e->getMessage()];
      $this->messenger()->addError($this->t('@label could not be read: @error', $args));
      return $form;
    }
    if (!$registration) {
      $args = ['@label' => $label, '@domain' => $name];
      $this->messenger()->addWarning($this->t("The @label account does not hold @domain. Compare the account on the provider's Registrations tab.", $args));
      return $form;
    }

    $expires = $registration->expires ? $this->dateFormatter->format($registration->expires, 'custom', 'Y-m-d') : NULL;
    $yes_no = fn(?bool $value) => $value === NULL ? $this->t('Unknown') : ($value ? $this->t('Yes') : $this->t('No'));
    $form['details'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Registrar'), $label],
        [$this->t('Expires'), $expires ?? $this->t('Unknown')],
        [$this->t('Status'), $registration->status ?? $this->t('Unknown')],
        [$this->t('Transfer lock'), $yes_no($registration->locked)],
        [$this->t('Auto-renew'), $yes_no($registration->autoRenew)],
      ],
    ];

    $form['nameservers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Nameservers'),
      '#description' => $this->t("One per line, at least two. Empty now means the domain uses @label's own parking or DNS.", ['@label' => $label]),
      '#default_value' => implode("\n", $nameservers),
      '#rows' => 4,
    ];
    $form['save_nameservers'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save nameservers'),
      '#validate' => ['::validateNameservers'],
      '#submit' => ['::saveNameservers'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['lock'] = [
      '#type' => 'submit',
      '#value' => $registration->locked === FALSE ? $this->t('Lock transfers') : $this->t('Unlock transfers'),
      '#lock' => $registration->locked === FALSE,
      '#limit_validation_errors' => [],
      '#submit' => ['::toggleLock'],
    ];
    $form['actions']['renew'] = [
      '#type' => 'submit',
      '#value' => $registration->autoRenew ? $this->t('Turn auto-renew off') : $this->t('Turn auto-renew on'),
      '#auto_renew' => !$registration->autoRenew,
      '#limit_validation_errors' => [],
      '#submit' => ['::toggleAutoRenew'],
    ];
    $form['actions']['auth_code'] = [
      '#type' => 'submit',
      '#value' => $this->t('Get the transfer auth code'),
      '#limit_validation_errors' => [],
      '#submit' => ['::authCode'],
    ];

    if ($code = $form_state->get('auth_code')) {
      $form['code'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Transfer auth code'),
        '#value' => $code,
        '#attributes' => ['readonly' => 'readonly'],
        '#weight' => 100,
      ];
      $form_state->set('auth_code', NULL);
    }
    return $form;
  }

  /**
   * Checks the nameservers are host names, at least two.
   */
  public function validateNameservers(array &$form, FormStateInterface $form_state): void {
    $hosts = $this->nameservers($form_state);
    if (count($hosts) < 2) {
      $form_state->setErrorByName('nameservers', $this->t('Enter at least two nameservers.'));
      return;
    }
    foreach ($hosts as $host) {
      if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
        $form_state->setErrorByName('nameservers', $this->t('%host is not a host name.', ['%host' => $host]));
      }
    }
  }

  /**
   * Replaces the nameservers.
   */
  public function saveNameservers(array &$form, FormStateInterface $form_state): void {
    $hosts = $this->nameservers($form_state);
    $this->act($form_state, 'nameservers set to ' . implode(', ', $hosts), fn(DomainManagementInterface $plugin, string $name) => $plugin->setNameservers($name, $hosts));
  }

  /**
   * Locks or unlocks transfers.
   */
  public function toggleLock(array &$form, FormStateInterface $form_state): void {
    $lock = (bool) $form_state->getTriggeringElement()['#lock'];
    $this->act($form_state, $lock ? 'transfer lock on' : 'transfer lock off', fn(DomainManagementInterface $plugin, string $name) => $plugin->setTransferLock($name, $lock));
  }

  /**
   * Turns automatic renewal on or off.
   */
  public function toggleAutoRenew(array &$form, FormStateInterface $form_state): void {
    $on = (bool) $form_state->getTriggeringElement()['#auto_renew'];
    $this->act($form_state, $on ? 'auto-renew on' : 'auto-renew off', fn(DomainManagementInterface $plugin, string $name) => $plugin->setAutoRenew($name, $on));
  }

  /**
   * Fetches the auth code, for this page only.
   */
  public function authCode(array &$form, FormStateInterface $form_state): void {
    $this->act($form_state, 'transfer auth code requested', function (DomainManagementInterface $plugin, string $name) use ($form_state): void {
      $code = $plugin->getAuthCode($name);
      if ($code === '') {
        $this->messenger()->addStatus($this->t("The registrar emailed the auth code for @domain to the domain's administrative contact.", ['@domain' => $name]));
      }
      $form_state->set('auth_code', $code);
    }, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Runs one operation at the registrar, logs it and rebuilds the page.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $change
   *   What changed, for the log.
   * @param callable $operation
   *   Called with the plugin and the domain.
   * @param bool $announce
   *   Whether to confirm the change in a message.
   */
  protected function act(FormStateInterface $form_state, string $change, callable $operation, bool $announce = TRUE): void {
    $form_state->setRebuild();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($form_state->get('product'));
    $provider = self::provider($product);
    $name = (string) $product->get('domain')->value;
    $plugin = $provider->getPlugin();
    assert($plugin instanceof DomainManagementInterface);
    $context = ['@domain' => $name, '@provider' => $provider->label(), '@change' => $change];
    try {
      $operation($plugin, $name);
    }
    catch (ProviderException $e) {
      $args = ['@provider' => $provider->label(), '@error' => $e->getMessage()];
      $this->messenger()->addError($this->t('@provider refused: @error', $args));
      $this->logger->warning('@domain at @provider: @change failed: @error', $context + ['@error' => $e->getMessage()]);
      return;
    }
    $this->logger->notice('@domain at @provider: @change.', $context);
    if ($announce) {
      $this->messenger()->addStatus($this->t('@domain at @provider: @change.', $context));
    }
  }

  /**
   * Returns the entered nameservers, normalized.
   *
   * @return string[]
   *   Lowercase host names without a trailing dot, in order.
   */
  protected function nameservers(FormStateInterface $form_state): array {
    $hosts = preg_split('/[\s,]+/', strtolower((string) $form_state->getValue('nameservers')), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_map(fn(string $host) => rtrim($host, '.'), $hosts)));
  }

}

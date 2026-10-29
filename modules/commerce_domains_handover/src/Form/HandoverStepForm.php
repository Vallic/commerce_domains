<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Form;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_domains_handover\HandoverException;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms one handover step, and takes it.
 *
 * Steps that reach the registrar act there at once. The auth code is fetched
 * and emailed within the submission; the seller who enters one types it into
 * a password field, which is never shown back, and the form is never cached.
 */
final class HandoverStepForm extends ConfirmFormBase {

  /**
   * The handover.
   */
  protected HandoverInterface $handover;

  /**
   * The step: a workflow transition.
   */
  protected string $transition;

  public function __construct(
    protected HandoverManager $handoverManager,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(HandoverManager::class),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_handover_step';
  }

  /**
   * Allows a step that is open now.
   */
  public function access(HandoverInterface $commerce_domains_handover, string $transition): AccessResultInterface {
    $result = AccessResult::allowedIf($this->handoverManager->allows($commerce_domains_handover, $transition))
      ->addCacheableDependency($commerce_domains_handover);
    // Where the domain is, and whether that registrar's API can unlock it.
    foreach (array_filter([$commerce_domains_handover->getProduct(), $commerce_domains_handover->getRegistrar()]) as $dependency) {
      $result->addCacheableDependency($dependency);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?HandoverInterface $commerce_domains_handover = NULL, string $transition = ''): array {
    $this->handover = $commerce_domains_handover;
    $this->transition = $transition;
    $args = $this->args();

    if ($transition === 'send_code') {
      // An entered code must not outlive the request.
      $form_state->disableCache();
      $api = $this->handoverManager->management($this->handover) !== NULL;
      $form['auth_code'] = [
        '#type' => 'password',
        '#title' => $this->t('Auth code'),
        '#description' => $api
          ? $this->t('Leave it empty to get the code from @registrar. Enter one only if @registrar emailed it to you instead.', $args)
          : $this->t('Get the code from @registrar and enter it here.', $args),
        '#required' => !$api,
        '#size' => 40,
        '#maxlength' => 255,
        '#attributes' => ['autocomplete' => 'off'],
      ];
    }

    if ($transition === 'complete') {
      $form['transferred'] = [
        '#type' => 'date',
        '#title' => $this->t('Transferred on'),
        '#default_value' => DrupalDateTime::createFromTimestamp($this->time->getRequestTime())->format('Y-m-d'),
        '#required' => TRUE,
      ];
      // Only on the page, not on the submission: it reads the registrar.
      if (!$form_state->isProcessingInput() && $this->stillHeld()) {
        $this->messenger()->addWarning($this->t('The @registrar account still holds @domain. Mark it transferred once the domain has left: a registrar sync that finds it there records it there again.', $args));
      }
    }

    $form = parent::buildForm($form, $form_state);
    // What the step does, before what it asks.
    $form['description']['#weight'] = -10;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    $args = $this->args();
    return match ($this->transition) {
      'unlock' => $this->t('Unlock @domain at @registrar?', $args),
      'send_code' => $this->t('Email the auth code for @domain to the buyer?', $args),
      'push' => $this->t('Mark @domain pushed to the buyer?', $args),
      'complete' => $this->t('Mark @domain transferred to the buyer?', $args),
      default => $this->t('Cancel the handover of @domain?', $args),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    $args = $this->args();
    return match ($this->transition) {
      'unlock' => $this->t('This turns the transfer lock off at @registrar at once. Anyone with the auth code can then transfer the domain.', $args),
      'send_code' => $this->t('The code goes into an email to @mail, with how to use it, and nowhere else: it is not shown here, kept or logged.', $args),
      'push' => $this->t('Pushes between accounts have no API here. First push @domain at @registrar to @to; marking it pushed then emails the buyer that it is on its way.', $args),
      'complete' => $this->handover->getRegistrar()
        ? $this->t('Mark it once @domain has left your account at @registrar. The domain is then no longer recorded at @registrar, so registrar syncs stop expecting it there, and the buyer is emailed that it is theirs.', $args)
        : $this->t('Mark it once @domain has left your account. The buyer is then emailed that it is theirs.', $args),
      default => $this->t('The buyer is not emailed, and the domain stays off sale. If it was unlocked for the buyer, lock it again at @registrar.', $args),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return match ($this->transition) {
      'unlock' => $this->t('Unlock'),
      'send_code' => $this->t('Send the auth code'),
      'push' => $this->t('Mark pushed'),
      'complete' => $this->t('Mark transferred'),
      default => $this->t('Cancel the handover'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText(): TranslatableMarkup {
    return $this->t('Back');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('commerce_domains_handover.order', ['commerce_order' => $this->handover->getOrderId()]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($this->transition !== 'complete' || $form_state->getValue('transferred') === NULL) {
      return;
    }
    $time = $this->transferredTime($form_state);
    if ($time === NULL) {
      $form_state->setErrorByName('transferred', $this->t('Enter the date the domain was transferred.'));
    }
    elseif ($time > $this->time->getRequestTime()) {
      $form_state->setErrorByName('transferred', $this->t('The transfer date cannot be in the future.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirectUrl($this->getCancelUrl());
    $args = $this->args();
    try {
      switch ($this->transition) {
        case 'unlock':
          $this->handoverManager->unlock($this->handover);
          $this->messenger()->addStatus($this->t('@domain is unlocked at @registrar.', $args));
          break;

        case 'send_code':
          $this->handoverManager->sendAuthCode($this->handover, (string) $form_state->getValue('auth_code'));
          $this->messenger()->addStatus($this->t('The auth code for @domain was emailed to @mail.', $args));
          break;

        case 'push':
          $mailed = $this->handoverManager->push($this->handover);
          $this->messenger()->addStatus($this->t('@domain is marked pushed to the buyer.', $args));
          $this->mailed($mailed, $args);
          break;

        case 'complete':
          $mailed = $this->handoverManager->complete($this->handover, $this->transferredTime($form_state));
          $this->messenger()->addStatus($this->handover->getRegistrar()
            ? $this->t('@domain is marked transferred, and no longer expected at @registrar.', $args)
            : $this->t('@domain is marked transferred.', $args));
          $this->mailed($mailed, $args);
          break;

        case 'cancel':
          $this->handoverManager->cancel($this->handover);
          $this->messenger()->addStatus($this->t('The handover of @domain is canceled.', $args));
          break;
      }
    }
    catch (ProviderException $e) {
      $this->messenger()->addError($this->t('@registrar refused: @error', $args + ['@error' => $e->getMessage()]));
    }
    catch (HandoverException $e) {
      $this->messenger()->addError($e->reason);
      // The seller can enter the code the registrar emailed them.
      if ($this->transition === 'send_code' && $this->handoverManager->allows($this->handover, 'send_code')) {
        $form_state->setRedirect('commerce_domains_handover.step', [
          'commerce_domains_handover' => $this->handover->id(),
          'transition' => 'send_code',
        ]);
      }
    }
    // Nothing keeps the code after this request.
    $form_state->setValue('auth_code', NULL);
  }

  /**
   * Warns when the buyer's email could not be sent.
   */
  protected function mailed(bool $mailed, array $args): void {
    if (!$mailed) {
      $this->messenger()->addWarning($this->t('The email to the buyer, @mail, could not be sent.', $args));
    }
  }

  /**
   * Returns the entered transfer date as a Unix time.
   *
   * Today means now; an earlier day means noon on that day.
   *
   * @return int|null
   *   The time, or NULL for a value that isn't a date.
   */
  protected function transferredTime(FormStateInterface $form_state): ?int {
    $value = (string) $form_state->getValue('transferred');
    $now = $this->time->getRequestTime();
    if ($value === DrupalDateTime::createFromTimestamp($now)->format('Y-m-d')) {
      return $now;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return NULL;
    }
    try {
      return DrupalDateTime::createFromFormat('Y-m-d H:i:s', $value . ' 12:00:00')->getTimestamp();
    }
    catch (\InvalidArgumentException | \UnexpectedValueException) {
      return NULL;
    }
  }

  /**
   * Whether the seller's registrar account still holds the domain.
   *
   * FALSE when it can't be told: no registrar API, or the API failed.
   */
  protected function stillHeld(): bool {
    $provider = $this->handover->getRegistrar();
    $plugin = $provider && $provider->status() ? $provider->getPlugin() : NULL;
    if (!$plugin instanceof RegistrarInterface) {
      return FALSE;
    }
    $name = $this->handover->getDomain();
    try {
      return isset($plugin->fetchRegistrations([$name])[$name]);
    }
    catch (ProviderException) {
      return FALSE;
    }
  }

  /**
   * Returns the placeholders the texts share.
   *
   * Steps that need the registrar's name are only open with a registrar, so
   * the others read well with "the registrar".
   */
  protected function args(): array {
    $transfer = $this->handover->getTransfer();
    $account = (string) $transfer?->account;
    return [
      '@domain' => $this->handover->getDomain(),
      '@registrar' => (string) ($this->handover->getRegistrar()?->label() ?? $this->t('the registrar')),
      '@mail' => (string) $this->handover->getOrder()?->getEmail(),
      '@to' => $transfer?->method === Transfer::PUSH && $account !== ''
        ? $this->t("the buyer's account, @account", ['@account' => $account])
        : $this->t("the buyer's account"),
    ];
  }

}

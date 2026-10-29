<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_checkout\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Asks where the domain should go once it is paid for.
 *
 * The answer is kept on the order (see Transfer), which this pane reads back
 * when the buyer returns to the step, and written as the customer comment,
 * which is what the seller reads when the order arrives.
 *
 * The #states selectors come from the pane's #parents, so the pane works on
 * any step or in the sidebar.
 */
#[CommerceCheckoutPane(
  id: 'commerce_domains_transfer',
  label: new TranslatableMarkup('Domain transfer'),
  admin_description: new TranslatableMarkup('Asks the buyer where the domain should be transferred.'),
  default_step: 'transfer',
  wrapper_element: 'container',
)]
class DomainTransfer extends CheckoutPaneBase {

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $transfer = Transfer::fromOrder($this->order) ?? new Transfer();
    $registrar = $this->registrar();
    $selector = ':input[name="' . $this->inputName($pane_form, 'method') . '"]';

    $pane_form['method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Where should the domain go?'),
      '#options' => [
        Transfer::PUSH => $this->t('Push the domain to my account at @registrar (fastest, usually the same day)', ['@registrar' => $registrar]),
        Transfer::TRANSFER => $this->t('Transfer the domain to a different registrar'),
      ],
      '#default_value' => $transfer->method,
    ];
    $pane_form['account'] = [
      '#type' => 'textarea',
      '#title' => Transfer::currentRegistrar($this->order) === NULL
        ? $this->t('Your account at the current registrar')
        : $this->t('Your @registrar account', ['@registrar' => $registrar]),
      '#description' => $this->t('The username or the email address the account is under. Your registrar can tell you which one it needs for a push between accounts.'),
      '#rows' => 3,
      '#default_value' => $transfer->account,
      '#states' => [
        'visible' => [$selector => ['value' => Transfer::PUSH]],
        'required' => [$selector => ['value' => Transfer::PUSH]],
      ],
    ];
    $pane_form['registrar'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your registrar'),
      '#description' => $this->t('Wherever you want the domain to end up.'),
      '#default_value' => $transfer->registrar,
      '#states' => [
        'visible' => [$selector => ['value' => Transfer::TRANSFER]],
        'required' => [$selector => ['value' => Transfer::TRANSFER]],
      ],
    ];
    return $pane_form;
  }

  /**
   * {@inheritdoc}
   *
   * #states only marks the fields required in the browser.
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    parent::validatePaneForm($pane_form, $form_state, $complete_form);
    $values = $form_state->getValue($pane_form['#parents']);
    $method = $values['method'] ?? Transfer::PUSH;
    if ($method === Transfer::TRANSFER && trim((string) ($values['registrar'] ?? '')) === '') {
      $form_state->setError($pane_form['registrar'], $this->t('Tell us which registrar the domain should be transferred to.'));
    }
    if ($method === Transfer::PUSH && trim((string) ($values['account'] ?? '')) === '') {
      $form_state->setError($pane_form['account'], $this->t('Tell us the account the domain should be pushed to.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    parent::submitPaneForm($pane_form, $form_state, $complete_form);
    $values = $form_state->getValue($pane_form['#parents']);
    $transfer = ($values['method'] ?? '') === Transfer::TRANSFER
      ? new Transfer(Transfer::TRANSFER, registrar: trim((string) $values['registrar']))
      : new Transfer(Transfer::PUSH, account: trim((string) ($values['account'] ?? '')));
    $transfer->toOrder($this->order);
    $this->order->setCustomerComments((string) $this->describe($transfer));
  }

  /**
   * {@inheritdoc}
   *
   * Shown on the review step, so the buyer can check where the domain goes.
   */
  public function buildPaneSummary(): array {
    $transfer = Transfer::fromOrder($this->order);
    return $transfer ? ['#plain_text' => (string) $this->describe($transfer)] : [];
  }

  /**
   * Says where the domain goes.
   */
  protected function describe(Transfer $transfer): TranslatableMarkup {
    return $transfer->describe(Transfer::currentRegistrar($this->order));
  }

  /**
   * Names the registrar holding the domain.
   *
   * Named, because "the current registrar" doesn't tell a buyer whether they
   * have an account there.
   */
  protected function registrar(): string {
    return Transfer::currentRegistrar($this->order) ?? (string) $this->t('the current registrar');
  }

  /**
   * Returns the HTML name of one of the pane's fields, wherever it is placed.
   */
  protected function inputName(array $pane_form, string $field): string {
    $parents = [...$pane_form['#parents'], $field];
    $name = array_shift($parents);
    foreach ($parents as $parent) {
      $name .= '[' . $parent . ']';
    }
    return $name;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Form;

use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Starts the handover of an order's domains by hand.
 *
 * Payment starts handovers by itself. This is for the rest: an order paid
 * before this module was installed, a payment the site never recorded, or an
 * Escrow.com sale the seller wants to follow here too.
 */
final class HandoverStartForm extends ConfirmFormBase {

  /**
   * The order.
   */
  protected OrderInterface $order;

  public function __construct(
    protected HandoverManager $handoverManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(HandoverManager::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_handover_start';
  }

  /**
   * Allows placed orders with domains not handed over yet.
   */
  public function access(OrderInterface $commerce_order): AccessResultInterface {
    return AccessResult::allowedIf($this->handoverManager->isPlaced($commerce_order) && $this->domains($commerce_order) !== '')
      ->addCacheableDependency($commerce_order)
      ->addCacheTags(['commerce_domains_handover_list']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $commerce_order = NULL): array {
    $this->order = $commerce_order;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Start the handover of @domains?', ['@domains' => $this->domains($this->order)]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    $args = ['@mail' => (string) $this->order->getEmail()];
    return match (TRUE) {
      $this->handoverManager->isEscrow($this->order) => $this->t('Escrow.com runs the handover of this order and emails the buyer itself. The buyer is emailed what happens next here too, at @mail.', $args),
      !$this->order->isPaid() => $this->t('The order is not paid in full: start only once you have the money. The buyer is emailed what happens next, at @mail.', $args),
      default => $this->t('The buyer is emailed what happens next, at @mail.', $args),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Start the handover');
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
    return Url::fromRoute('commerce_domains_handover.order', ['commerce_order' => $this->order->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $started = $this->handoverManager->start($this->order, TRUE);
    $domains = implode(', ', array_map(fn($handover) => $handover->getDomain(), $started));
    if ($domains !== '') {
      $this->messenger()->addStatus($this->t('The handover of @domains has started. The buyer was emailed what happens next.', ['@domains' => $domains]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Names the order's domains that have no handover yet.
   */
  protected function domains(OrderInterface $order): string {
    $items = array_diff_key($this->handoverManager->domainItems($order), $this->handoverManager->handedOverItems($order));
    return implode(', ', array_map(fn($item) => $item->label(), $items));
  }

}

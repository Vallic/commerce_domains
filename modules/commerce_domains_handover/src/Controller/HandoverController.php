<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Controller;

use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The order's Handover tab: each domain's handover and the steps open.
 *
 * Nothing here changes anything: every step is a confirmation form of its
 * own. No auth code is ever shown.
 */
final class HandoverController extends ControllerBase {

  public function __construct(
    protected HandoverManager $handoverManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(HandoverManager::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Allows the tab on orders that sell domains.
   */
  public function access(OrderInterface $commerce_order): AccessResultInterface {
    return AccessResult::allowedIf((bool) $this->handoverManager->domainItems($commerce_order))
      ->addCacheableDependency($commerce_order);
  }

  /**
   * Shows the order's handovers.
   */
  public function page(OrderInterface $commerce_order): array {
    $order = $commerce_order;
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($order)
      ->addCacheTags(['commerce_domains_handover_list'])
      ->addCacheContexts(['user.permissions']);

    $build = [];
    foreach ($this->handoverManager->forOrder($order) as $handover) {
      $build['handover_' . $handover->id()] = $this->handover($handover, $cacheability);
    }
    $build['pending'] = $this->pending($order, $cacheability);
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Builds one handover: its details, the next step and the step buttons.
   */
  protected function handover(HandoverInterface $handover, CacheableMetadata $cacheability): array {
    $cacheability->addCacheableDependency($handover);
    $order = $handover->getOrder();
    $product = $handover->getProduct();
    $provider = $handover->getRegistrar();
    $transfer = $handover->getTransfer();
    $registrar = (string) $provider?->label();
    foreach (array_filter([$product, $provider]) as $dependency) {
      $cacheability->addCacheableDependency($dependency);
    }

    $registrar_cell = $registrar !== '' ? ['#plain_text' => $registrar] : ['#markup' => $this->t('None recorded')];
    if ($product && $registrar !== '' && $handover->isOpen()) {
      // Live details and the lock, for a registrar whose API manages domains.
      $url = Url::fromRoute('commerce_domains.product_registrar', ['commerce_product' => $product->id()]);
      $access = $url->access(NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $registrar_cell = ['#type' => 'link', '#title' => $registrar, '#url' => $url];
      }
    }
    $rows = [
      [$this->t('State'), $handover->getState()->getLabel()],
      [$this->t('Where to'), $transfer ? $transfer->describe($registrar) : $this->t('The buyer was not asked.')],
      [$this->t('Buyer'), (string) $order?->getEmail()],
      [$this->t('Registrar'), ['data' => $registrar_cell]],
      [$this->t('Started'), $this->dateFormatter->format($handover->getCreatedTime(), 'short')],
    ];
    if ($time = $handover->getTransferredTime()) {
      $rows[] = [$this->t('Transferred'), $this->dateFormatter->format($time, 'short')];
    }

    return [
      '#type' => 'details',
      '#title' => $handover->getDomain(),
      '#open' => TRUE,
      'details' => [
        '#type' => 'table',
        '#rows' => $rows,
      ],
      'next' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->next($handover, $transfer, $registrar),
      ],
      'steps' => $this->steps($handover, $transfer, $cacheability),
    ];
  }

  /**
   * Says what the seller does next.
   */
  protected function next(HandoverInterface $handover, ?Transfer $transfer, string $registrar): TranslatableMarkup {
    $args = [
      '@domain' => $handover->getDomain(),
      '@registrar' => $registrar !== '' ? $registrar : (string) $this->t('the registrar'),
      '@account' => (string) $transfer?->account,
      '@new_registrar' => (string) $transfer?->registrar,
      '@mail' => (string) $handover->getOrder()?->getEmail(),
    ];
    $state = $handover->getState()->getId();
    if ($state === 'waiting' && !$transfer) {
      return $this->t('The buyer was not asked where the domain should go: ask them at @mail, then push the domain or email them its auth code.', $args);
    }
    return match ($state) {
      'waiting' => match (TRUE) {
        $transfer->method === Transfer::PUSH => $this->t("At @registrar, push @domain to the buyer's account, @account. Then mark it pushed: the buyer is emailed that it is on its way.", $args),
        $this->handoverManager->allows($handover, 'unlock') => $this->t('Unlock @domain and email the buyer its auth code, for their transfer to @new_registrar.', $args),
        default => $this->t('Unlock @domain at @registrar, then email the buyer its auth code, for their transfer to @new_registrar.', $args),
      },
      'unlocked' => $this->t('Email the buyer the auth code for @domain.', $args),
      'code_sent' => $this->t('The buyer has the auth code. Mark the handover transferred once @domain has left your account at @registrar.', $args),
      'pushed' => $this->t("Mark the handover transferred once @domain is in the buyer's account; their registrar may ask them to accept it first.", $args),
      'transferred' => $this->t("@domain is the buyer's.", $args),
      default => $this->t('Canceled. If @domain was unlocked for the buyer, lock it again at @registrar.', $args),
    };
  }

  /**
   * Builds the buttons to the steps open now, the next one first.
   *
   * Steps off the way the buyer chose stay open, smaller, for a buyer who
   * changes their mind; canceling is a link, as deleting is in Claro.
   */
  protected function steps(HandoverInterface $handover, ?Transfer $transfer, CacheableMetadata $cacheability): array {
    $state = $handover->getState()->getId();
    $labels = [
      'unlock' => $this->t('Unlock at @registrar', ['@registrar' => (string) $handover->getRegistrar()?->label()]),
      'send_code' => $state === 'code_sent' ? $this->t('Email the auth code again') : $this->t('Email the auth code'),
      'push' => $this->t('Mark pushed'),
      'complete' => $this->t('Mark transferred'),
      'cancel' => $this->t('Cancel the handover'),
    ];
    // The step the seller is most likely to take next goes first.
    $primary = match ($state) {
      'waiting' => match (TRUE) {
        $transfer?->method === Transfer::PUSH => 'push',
        $this->handoverManager->allows($handover, 'unlock') => 'unlock',
        default => 'send_code',
      },
      'unlocked' => 'send_code',
      default => 'complete',
    };
    $aside = match ($transfer?->method) {
      Transfer::PUSH => ['unlock', 'send_code'],
      Transfer::TRANSFER => ['push'],
      default => [],
    };
    // Then the other steps on the buyer's way, the others, and canceling.
    $sequence = array_unique([$primary, ...array_diff(array_keys($labels), [...$aside, 'cancel']), ...$aside, 'cancel']);
    $labels = array_replace(array_flip($sequence), $labels);

    $steps = ['#type' => 'actions'];
    foreach ($labels as $transition => $label) {
      $url = Url::fromRoute('commerce_domains_handover.step', [
        'commerce_domains_handover' => $handover->id(),
        'transition' => $transition,
      ]);
      $access = $url->access(NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $classes = match (TRUE) {
        $transition === $primary => ['button', 'button--primary'],
        $transition === 'cancel' => ['action-link', 'action-link--danger', 'action-link--icon-trash'],
        in_array($transition, $aside, TRUE) => ['button', 'button--small'],
        default => ['button'],
      };
      $steps[$transition] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $url,
        '#attributes' => ['class' => $classes],
      ];
    }
    return $steps;
  }

  /**
   * Says why the order's other domains have no handover yet.
   */
  protected function pending(OrderInterface $order, CacheableMetadata $cacheability): array {
    $items = array_diff_key($this->handoverManager->domainItems($order), $this->handoverManager->handedOverItems($order));
    if (!$items) {
      return [];
    }
    $args = ['@domains' => implode(', ', array_map(fn($item) => $item->label(), $items))];
    $state = $order->getState()->getId();
    $message = match (TRUE) {
      $state === 'canceled' => $this->t('The order is canceled, so @domains stay with you.', $args),
      $state === 'draft' => $this->t('The handover of @domains starts once the order is placed and paid in full.', $args),
      $this->handoverManager->isEscrow($order) => $this->t('Escrow.com runs the handover of @domains: follow it there. Start one here only to email the buyer from this site as well.', $args),
      !$order->isPaid() => $this->t('The handover of @domains starts once the order is paid in full. Record a payment made outside the site, such as a bank transfer, on the Payments tab when it arrives.', $args),
      default => $this->t('The order is paid, but the handover of @domains has not started. Start it to email the buyer what happens next.', $args),
    };
    $build = [
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $message,
      ],
    ];
    $url = Url::fromRoute('commerce_domains_handover.start', ['commerce_order' => $order->id()]);
    $access = $url->access(NULL, TRUE);
    $cacheability->addCacheableDependency($access);
    if ($access->isAllowed()) {
      $build['start'] = [
        '#type' => 'actions',
        'start' => [
          '#type' => 'link',
          '#title' => $this->t('Start the handover'),
          '#url' => $url,
          '#attributes' => ['class' => ['button']],
        ],
      ];
    }
    return $build;
  }

}

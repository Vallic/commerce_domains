<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Controller;

use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_domains_owner\Checklist;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_domains_owner\SalesReport;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The owner's dashboard: what needs them, and how the syncs are doing.
 *
 * Built from what the site already knows: nothing is asked of a registrar
 * or marketplace while the page renders.
 */
final class DashboardController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  // Rows in each list.
  protected const ROWS = 10;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected Checklist $checklist,
    protected Renewals $renewals,
    protected SalesReport $salesReport,
    protected Notifier $notifier,
    protected RegistrarSync $registrarSync,
  ) {}

  /**
   * Builds the dashboard.
   */
  public function build(): array {
    return [
      'checklist' => $this->checklist(),
      'offers' => $this->offers(),
      'sales' => $this->sales(),
      'expiring' => $this->expiring(),
      'marketplaces' => $this->marketplaces(),
      'registrars' => $this->registrars(),
      // Sync records live in State, which has no cache tags.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds the next steps of setting up the site.
   */
  protected function checklist(): array {
    $steps = $this->checklist->steps();
    $rows = [];
    foreach ($steps as $id => $step) {
      $title = $step['url'] && !$step['done'] ? Link::fromTextAndUrl($step['title'], $step['url'])->toRenderable() : ['#plain_text' => $step['title']];
      $rows[$id] = [
        'data' => [$step['done'] ? $this->t('Done') : $this->t('To do'), ['data' => $title], $step['help']],
        'class' => [$step['done'] ? 'color-success' : 'color-warning'],
      ];
    }
    $open = count(array_filter($steps, fn(array $step) => !$step['done']));
    return [
      '#type' => 'details',
      '#title' => $open ? $this->formatPlural($open, 'Next steps: 1 to do', 'Next steps: @count to do') : $this->t('Next steps: all done'),
      '#open' => (bool) $open,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Status'), $this->t('Step'), $this->t('How')],
        '#rows' => $rows,
      ],
    ];
  }

  /**
   * Builds the offers that wait for an answer.
   */
  protected function offers(): array {
    if (!$this->moduleHandler->moduleExists('commerce_domains_offer')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_domains_offer');
    $count = fn(string $state) => (int) $storage->getQuery()->accessCheck(FALSE)->condition('state', $state)->count()->execute();
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('state', 'pending')
      ->sort('created', 'DESC')
      ->range(0, self::ROWS)
      ->execute();
    $rows = [];
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $offer */
    foreach ($storage->loadMultiple($ids) as $offer) {
      $rows[] = [
        ['data' => $offer->toLink($offer->getDomain())->toRenderable()],
        // Offers negotiated on Escrow.com have no amount on the site.
        $this->notifier->money($offer->getAmount()) ?: $this->t('On Escrow.com'),
        $offer->getBuyerName() ?: $offer->getEmail(),
        $this->dateFormatter->format((int) $offer->get('created')->value, 'short'),
      ];
    }
    $pending = $count('pending');
    return [
      '#type' => 'details',
      '#title' => $this->formatPlural($pending, 'Offers: 1 waits for an answer', 'Offers: @count wait for an answer'),
      '#open' => TRUE,
      'others' => [
        '#markup' => '<p>' . $this->t('Counter-offers waiting for buyers: @countered. Accepted offers waiting for payment: @accepted.', [
          '@countered' => $count('countered'),
          '@accepted' => $count('accepted'),
        ]) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Domain'), $this->t('Offer'), $this->t('Buyer'), $this->t('Made')],
        '#rows' => $rows,
        '#empty' => $this->t('No offer waits for an answer.'),
      ],
      'more' => $this->more($this->t('All offers'), 'entity.commerce_domains_offer.collection'),
    ];
  }

  /**
   * Builds the latest sales.
   */
  protected function sales(): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Recent sales'),
      '#open' => TRUE,
    ];
    if (!$this->renewals->hasCosts()) {
      $build['help']['#markup'] = '<p>' . $this->t('Turn on the <em>Domain costs and sale</em> trait for your domain product type to record sales and their profit.') . '</p>';
      return $build;
    }
    $build['table'] = $this->salesReport->table($this->salesReport->soldIds(self::ROWS));
    $build['more'] = $this->more($this->t('Sales and profit'), 'commerce_domains_owner.profit');
    return $build;
  }

  /**
   * Builds the domains expiring soon.
   */
  protected function expiring(): array {
    $days = $this->renewals->days();
    $now = $this->time->getRequestTime();
    $total = (int) $this->renewals->query($days)?->count()->execute();
    $rows = [];
    foreach ($this->renewals->expiring($days, self::ROWS) as $product) {
      $expires = (int) $product->get('domain_expires')->value;
      $auto_renew = Renewals::autoRenews($product);
      $rows[] = [
        ['data' => $product->toLink()->toRenderable()],
        $this->t('@date (@when)', [
          '@date' => $this->dateFormatter->format($expires, 'custom', 'Y-m-d'),
          '@when' => Renewals::when($expires, $now),
        ]),
        Renewals::decisionLabel($product),
        $auto_renew === NULL ? $this->t('Unknown') : ($auto_renew ? $this->t('On') : $this->t('Off')),
        $product->get('domain_registrar')->entity?->label() ?? '',
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->formatPlural($total, 'Expiring within @days days: 1 domain', 'Expiring within @days days: @count domains', ['@days' => $days]),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Domain'),
          $this->t('Expires'),
          $this->t('Renewal'),
          $this->t('Auto-renew'),
          $this->t('Registrar'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No domain expires within @days days.', ['@days' => $days]),
      ],
      'more' => $this->more($this->t('Keep or let expire'), 'commerce_domains_owner.expiring'),
    ];
  }

  /**
   * Builds the sync health of each marketplace.
   *
   * From what Commerce Domains Marketplace recorded of its syncs, and the
   * failures it reported.
   */
  protected function marketplaces(): array {
    if (!$this->moduleHandler->moduleExists('commerce_domains_marketplace')) {
      return [];
    }
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    $providers = array_filter($providers, fn(ProviderInterface $provider) => $provider->hasCapability(MarketplaceInterface::class));

    $query = $this->database->select(ListingRecorder::TABLE, 'l')->fields('l', ['provider']);
    $query->addExpression('MAX([l].[checked])', 'checked');
    $query->addExpression('MAX([l].[pushed])', 'pushed');
    $query->addExpression('SUM(CASE WHEN [l].[status] = :failed THEN 1 ELSE 0 END)', 'failed', [':failed' => ListingRecorder::FAILED]);
    $query->addExpression('SUM(CASE WHEN [l].[status] = :outdated THEN 1 ELSE 0 END)', 'outdated', [':outdated' => ListingRecorder::OUTDATED]);
    $stats = $query->groupBy('l.provider')->execute()->fetchAllAssoc('provider');

    $rows = [];
    foreach ($providers as $id => $provider) {
      $row = $stats[$id] ?? NULL;
      $failure = $this->notifier->lastSyncFailure((string) $id);
      $rows[$id] = [
        ['data' => $provider->toLink(NULL, 'edit-form')->toRenderable()],
        $provider->status() ? (MarketplaceSettings::isAutoSync($provider) ? $this->t('Automatic') : $this->t('By hand')) : $this->t('Disabled'),
        $this->date($row?->checked ? (int) $row->checked : NULL),
        $this->date($row?->pushed ? (int) $row->pushed : NULL),
        (int) ($row->outdated ?? 0),
        (int) ($row->failed ?? 0),
        $failure ? $this->t('@date: @message', [
          '@date' => $this->date($failure['time']),
          '@message' => $failure['message'],
        ]) : '',
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Marketplace syncs'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Marketplace'),
          $this->t('Pushes'),
          $this->t('Last checked'),
          $this->t('Last pushed'),
          $this->t('Out of date'),
          $this->t('Refused'),
          $this->t('Last failure'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No marketplace is set up.'),
      ],
      'queue' => $this->queue(),
      'more' => $this->more($this->t('Marketplace listings'), 'view.commerce_domains_listings.page_1'),
    ];
  }

  /**
   * Summarizes the automatic pushes waiting in the queue.
   */
  protected function queue(): array {
    if (!$this->entityTypeManager->hasDefinition('advancedqueue_queue')) {
      return [];
    }
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(SyncScheduler::QUEUE);
    if (!$queue instanceof QueueInterface) {
      return [];
    }
    $counts = $queue->getBackend()->countJobs();
    return [
      '#markup' => '<p>' . $this->t('Automatic pushes waiting: @queued. Failed for good: @failed; a whole sync catches up on those.', [
        '@queued' => (int) ($counts['queued'] ?? 0) + (int) ($counts['processing'] ?? 0),
        '@failed' => (int) ($counts['failure'] ?? 0),
      ]) . '</p>',
    ];
  }

  /**
   * Builds when each registrar account was last read.
   */
  protected function registrars(): array {
    $rows = [];
    foreach ($this->renewals->registrars() as $id => $provider) {
      $check = $this->registrarSync->lastCheck((string) $id);
      $missing = $check ? count($check['missing']) : 0;
      $missing_cell = $missing ? $this->formatPlural($missing, '1 domain', '@count domains') : '0';
      $rows[$id] = [
        ['data' => $provider->toLink(NULL, 'edit-form')->toRenderable()],
        $provider->status() ? ($provider->syncsRegistrations() ? $this->t('Daily') : $this->t('By hand')) : $this->t('Disabled'),
        $check ? $this->date($check['time']) : $this->t('Never'),
        $missing ? ['data' => Link::fromTextAndUrl($missing_cell, $provider->toUrl('registrations'))->toRenderable()] : $missing_cell,
        (string) $provider->getPlugin()->getSetupError(),
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Registrar checks'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Registrar'),
          $this->t('Refresh'),
          $this->t('Account last read'),
          $this->t('Not in the account'),
          $this->t('Problem'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No registrar with an API is set up.'),
      ],
    ];
  }

  /**
   * Formats a time, or says never.
   */
  protected function date(?int $time): string {
    return $time ? $this->dateFormatter->format($time, 'short') : (string) $this->t('Never');
  }

  /**
   * Links to a fuller page, when the site has it.
   */
  protected function more(string|\Stringable $title, string $route): array {
    try {
      $url = Url::fromRoute($route);
      $url->toString();
    }
    catch (RouteNotFoundException) {
      return [];
    }
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => $url,
      '#access' => $url->access(),
    ];
  }

}

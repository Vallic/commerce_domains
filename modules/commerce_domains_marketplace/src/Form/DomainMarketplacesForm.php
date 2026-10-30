<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Form;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSettings;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncPlan;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a domain's listing on each marketplace, and checks or pushes it.
 *
 * The statuses are what the last sync recorded; checking reads the
 * marketplaces again for this domain alone, and pushing syncs it. A sale
 * either finds takes the domain off sale here.
 */
final class DomainMarketplacesForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MarketplaceSync $sync,
    protected ListingRecorder $recorder,
    protected Catalog $catalog,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(MarketplaceSync::class),
      $container->get(ListingRecorder::class),
      $container->get(Catalog::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_marketplace_domain_marketplaces';
  }

  /**
   * Allows the tab on domain products.
   */
  public static function access(ProductInterface $commerce_product): AccessResultInterface {
    return AccessResult::allowedIf($commerce_product->hasField('domain') && !$commerce_product->get('domain')->isEmpty())
      ->addCacheableDependency($commerce_product);
  }

  /**
   * Returns the enabled providers that list domains: by API, file or hand.
   *
   * @return \Drupal\commerce_domains\Entity\ProviderInterface[]
   *   The providers.
   */
  protected function providers(): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    return array_filter($providers, fn(ProviderInterface $provider) => $provider->status() && $provider->isMarketplace());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProductInterface $commerce_product = NULL): array {
    $product = $commerce_product;
    $name = (string) $product->get('domain')->value;
    $form_state->set('domain', $name);
    $item = $this->catalog->load([$name])[$name] ?? NULL;
    $rows = $this->recorder->forProducts([(int) $product->id()])[(int) $product->id()] ?? [];

    $form['summary'] = [
      '#type' => 'item',
      '#markup' => $this->summary($item, $rows),
    ];

    $labels = ListingRecorder::statusLabels();
    $date = fn($time) => $time ? $this->dateFormatter->format((int) $time, 'short') : '';
    $table = [];
    foreach ($this->providers() as $id => $provider) {
      if ($provider->hasCapability(MarketplaceInterface::class)) {
        $row = $rows[$id] ?? NULL;
        // E.g. a marketplace for the domains registered there only.
        $handled = !$item || MarketplaceSettings::plugin($provider)->manages($item);
        $details = $row->note ?? ($handled ? '' : $this->t('This marketplace does not list this domain.'));
        if ($row && $row->status === ListingRecorder::SOLD && $row->sold) {
          $details = $this->t('@status, found on @date.', ['@status' => $row->note, '@date' => $date($row->sold)]);
        }
        $table[$id] = [
          $provider->label(),
          $row ? ($labels[$row->status] ?? $row->status) : ($handled ? $this->t('Not checked yet') : $this->t('Not handled here')),
          $details,
          $date($row->checked ?? NULL),
          $date($row->pushed ?? NULL),
        ];
      }
      elseif ($provider->hasCapability(CsvExportInterface::class)) {
        $table[$id] = [
          $provider->label(),
          $this->inCsv($provider, $item) ? $this->t('In the CSV file') : $this->t('Left out of the CSV file'),
          $this->t('Uploaded by hand.'),
          '',
          '',
        ];
      }
      else {
        $table[$id] = [
          $provider->label(),
          $item && $item->forSale && $item->terms->listsOn((string) $id) ? $this->t('To list') : $this->t('To leave off'),
          $this->t('No API: list or remove it there by hand.'),
          '',
          '',
        ];
      }
    }
    $form['listings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Marketplace'),
        $this->t('Status'),
        $this->t('Details'),
        $this->t('Last checked'),
        $this->t('Last pushed'),
      ],
      '#rows' => $table,
      '#empty' => $this->t('No enabled provider lists domains.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['check'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check marketplaces'),
      '#submit' => ['::check'],
    ];
    $form['actions']['push'] = [
      '#type' => 'submit',
      '#value' => $this->t('Push to marketplaces'),
      '#submit' => ['::push'],
    ];
    return $form;
  }

  /**
   * Says whether the domain is for sale, and where the seller lists it.
   *
   * @param \Drupal\commerce_domains\CatalogItem|null $item
   *   The domain, as marketplaces see it.
   * @param array<string, object> $rows
   *   Its recorded listings, keyed by provider ID.
   */
  protected function summary(?CatalogItem $item, array $rows): string {
    $sold = array_keys(array_filter($rows, fn(object $row) => $row->status === ListingRecorder::SOLD));
    if ($sold && (!$item || !$item->forSale)) {
      $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple($sold);
      $labels = array_map(fn($provider) => $provider->label(), $providers) ?: $sold;
      return (string) $this->t('Sold on @providers: off sale here, and the other marketplaces remove its listings. Put it back on sale if the sale falls through.', ['@providers' => implode(', ', $labels)]);
    }
    if (!$item || !$item->forSale) {
      return (string) $this->t('Not for sale: marketplaces remove its listings on the next sync.');
    }
    if (!$item->terms->marketplaces) {
      return (string) $this->t('For sale on this site only, by its sale terms.');
    }
    if ($item->terms->notListedOn) {
      $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple($item->terms->notListedOn);
      $labels = array_map(fn($provider) => $provider->label(), $providers);
      return (string) $this->t('For sale; kept off @providers by its sale terms.', ['@providers' => implode(', ', $labels)]);
    }
    return (string) $this->t('For sale, and listed on every marketplace below.');
  }

  /**
   * Whether the provider's next CSV file has the domain.
   */
  protected function inCsv(ProviderInterface $provider, ?CatalogItem $item): bool {
    $plugin = $provider->getPlugin();
    if (!$item || !$item->forSale || !$item->terms->listsOn((string) $provider->id()) || !$plugin instanceof CsvExportInterface) {
      return FALSE;
    }
    foreach (array_keys($plugin->csvFiles()) as $file) {
      if ($plugin->csvRow($item, $file) !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Reads the marketplaces again for this domain, pushing nothing.
   *
   * A sale found there still takes the domain off sale here.
   */
  public function check(array &$form, FormStateInterface $form_state): void {
    $this->each($form_state, function (ProviderInterface $provider, string $name): void {
      $this->plan($provider, $name);
    });
    $this->messenger()->addStatus($this->t('Checked.'));
  }

  /**
   * Pushes this domain to the marketplaces that differ.
   */
  public function push(array &$form, FormStateInterface $form_state): void {
    $this->each($form_state, function (ProviderInterface $provider, string $name): void {
      $plan = $this->plan($provider, $name);
      if (!$plan->isEmpty()) {
        foreach ($this->sync->apply($provider, $plan) as $error) {
          $this->messenger()->addError($this->t('@provider refused it: @error', [
            '@provider' => $provider->label(),
            '@error' => $error,
          ]));
        }
      }
    });
    $this->messenger()->addStatus($this->t('Pushed where it differed.'));
  }

  /**
   * Runs an action for each marketplace with an API.
   */
  protected function each(FormStateInterface $form_state, callable $action): void {
    $name = (string) $form_state->get('domain');
    foreach ($this->providers() as $provider) {
      if (!$provider->hasCapability(MarketplaceInterface::class) || MarketplaceSettings::plugin($provider)->getSetupError()) {
        continue;
      }
      try {
        $action($provider, $name);
      }
      catch (ProviderException $e) {
        $this->sync->reportFailure($provider, ['' => $e->getMessage()]);
        $this->messenger()->addError($this->t('@provider: @error', [
          '@provider' => $provider->label(),
          '@error' => $e->getMessage(),
        ]));
      }
    }
  }

  /**
   * Checks one marketplace for the domain, saying when it found a sale.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function plan(ProviderInterface $provider, string $name): SyncPlan {
    $plan = $this->sync->plan($provider, [$name]);
    if ($plan->newSales) {
      $this->messenger()->addWarning($this->t('@provider shows it sold (@status): it is now off sale here.', [
        '@provider' => $provider->label(),
        '@status' => $plan->sales[$name] ?? '',
      ]));
    }
    return $plan;
  }

}

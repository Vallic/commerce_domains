<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\SalesReportInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills in domains' sales from the sales reports of marketplaces.
 *
 * A marketplace's listing only shows that a domain sold, and the time the
 * sale was found is recorded; the report adds the sale date, what it
 * brought after commission, and the payments of a lease to own. A sale is
 * only recorded once the report says what it brought. Cron reads each
 * report once a day, as amounts are often worked out days after a sale.
 * See SaleRecorder::saleChanges() for what is filled in.
 */
class MarketplaceSales {

  // How often cron reads the reports.
  public const INTERVAL = 86400;

  // The state key of when cron last read them.
  public const LAST_RUN_KEY = 'commerce_domains_owner.marketplace_sales';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Catalog $catalog,
    protected SaleRecorder $recorder,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the enabled providers set up to report sales.
   *
   * @return \Drupal\commerce_domains\Entity\ProviderInterface[]
   *   The providers, keyed by ID.
   */
  public function providers(): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    return array_filter($providers, fn(ProviderInterface $provider) => $provider->status()
      && $provider->hasCapability(SalesReportInterface::class)
      && $provider->getPlugin()->getSetupError() === NULL);
  }

  /**
   * Reads a provider's sales, and what each adds to its catalog domain.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The marketplace.
   * @param string[]|null $names
   *   The domains, normalized, or NULL for every sale.
   *
   * @return array<string, array{sale: \Drupal\commerce_domains\MarketplaceSale, product: \Drupal\commerce_product\Entity\ProductInterface|null, changes: array<string, mixed>}>
   *   Keyed by domain: the sale, its product (NULL outside the catalog) and
   *   the values a fill sets.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function plan(ProviderInterface $provider, ?array $names = NULL): array {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof SalesReportInterface) {
      return [];
    }
    $sales = $plugin->fetchSales($names);
    $products = [];
    if ($sales && $this->catalog->productTypes()) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      foreach ($this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => array_keys($sales)]) as $product) {
        $products[(string) $product->get('domain')->value] = $product;
      }
    }
    $plan = [];
    foreach ($sales as $name => $sale) {
      $product = $products[$name] ?? NULL;
      $plan[$name] = [
        'sale' => $sale,
        'product' => $product,
        'changes' => $product ? $this->recorder->saleChanges($product, $provider, $sale) : [],
      ];
    }
    return $plan;
  }

  /**
   * Fills in the sales a plan found.
   *
   * @return int
   *   How many domains changed.
   */
  public function apply(ProviderInterface $provider, array $plan): int {
    $count = 0;
    foreach ($plan as $entry) {
      if ($entry['product'] && $entry['changes'] && $this->recorder->fillSale($entry['product'], $provider, $entry['sale'])) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Reads every provider's report, once a day.
   *
   * @return int
   *   How many domains changed.
   */
  public function cron(): int {
    $now = $this->time->getRequestTime();
    if ($now - (int) $this->state->get(self::LAST_RUN_KEY, 0) < self::INTERVAL) {
      return 0;
    }
    $this->state->set(self::LAST_RUN_KEY, $now);
    $count = 0;
    foreach ($this->providers() as $provider) {
      try {
        $count += $this->apply($provider, $this->plan($provider));
      }
      catch (ProviderException $e) {
        $this->logger->warning('Reading the sales of @provider failed: @error', [
          '@provider' => $provider->label(),
          '@error' => $e->getMessage(),
        ]);
      }
    }
    return $count;
  }

}

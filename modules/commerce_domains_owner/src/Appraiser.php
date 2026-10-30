<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Appraisal;
use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\AppraisalInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Appraises the portfolio, a few domains each cron run.
 *
 * Appraisal services limit how many a day, so cron spreads the day's
 * allowance (appraisals_per_day in the settings) over its runs: domains never
 * appraised first, then the oldest appraisals once they are REFRESH old. Sold
 * domains are skipped. A failure, e.g. the service's own limit reached, ends
 * the day's appraisals.
 */
class Appraiser {

  // How long an appraisal stands before it is redone.
  public const REFRESH = 90 * 86400;

  // The most appraisals one cron run makes, so a run stays short.
  public const PER_RUN = 10;

  // The state key of today's count: ['day' => 'Y-m-d', 'count' => int].
  public const COUNT_KEY = 'commerce_domains_owner.appraisals';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Catalog $catalog,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the provider that appraises: the first enabled one set up to.
   */
  public function provider(): ?ProviderInterface {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      if ($provider->status() && $provider->hasCapability(AppraisalInterface::class) && $provider->getPlugin()->getSetupError() === NULL) {
        return $provider;
      }
    }
    return NULL;
  }

  /**
   * Returns the domains due for an appraisal, those never appraised first.
   *
   * @return int[]
   *   Product IDs.
   */
  public function due(int $limit): array {
    $types = $this->catalog->productTypes();
    // The fields come with the costs trait.
    $has_fields = isset($this->entityFieldManager->getFieldStorageDefinitions('commerce_product')['domain_appraised']);
    if (!$types || !$has_fields || $limit < 1) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage('commerce_product')->getQuery()->accessCheck(FALSE);
    $stale = $query->orConditionGroup()
      ->notExists('domain_appraised')
      ->condition('domain_appraised', $this->time->getRequestTime() - self::REFRESH, '<');
    $ids = $query
      ->condition('type', $types, 'IN')
      ->notExists('domain_sold')
      ->condition($stale)
      ->sort('domain_appraised')
      ->sort('product_id')
      ->range(0, $limit)
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Appraises one domain and records the result on its product.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function appraise(ProductInterface $product, ProviderInterface $provider): Appraisal {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof AppraisalInterface || !$product->hasField('domain_appraised')) {
      throw new ProviderException(sprintf('%s does not appraise domains.', $provider->label()));
    }
    $appraisal = $plugin->appraise((string) $product->get('domain')->value);
    $notes = array_merge(
      array_map(fn(string $signal) => '+ ' . $signal, $appraisal->positive),
      array_map(fn(string $signal) => '− ' . $signal, $appraisal->negative),
    );
    $product->set('domain_appraisal', $appraisal->value === NULL ? NULL : new Price((string) $appraisal->value, 'USD'));
    $product->set('domain_appraisal_score', $appraisal->score);
    $product->set('domain_tld_count', $appraisal->tldCount);
    $product->set('domain_appraised', $this->time->getRequestTime());
    $product->set('domain_appraisal_notes', $notes ? implode("\n", $notes) : NULL);
    // An appraisal isn't an edit: keep the changed time.
    $product->setSyncing(TRUE);
    $product->save();
    return $appraisal;
  }

  /**
   * Returns how many appraisals today's allowance has left.
   */
  public function remainingToday(): int {
    $limit = max(0, (int) $this->configFactory->get('commerce_domains_owner.settings')->get('appraisals_per_day'));
    $today = $this->state->get(self::COUNT_KEY, []);
    $used = ($today['day'] ?? NULL) === $this->today() ? (int) $today['count'] : 0;
    return max(0, $limit - $used);
  }

  /**
   * Counts appraisals toward today's allowance.
   *
   * @param int|null $count
   *   How many were made; NULL uses up the rest of the day.
   */
  public function countToday(?int $count): void {
    $today = $this->state->get(self::COUNT_KEY, []);
    $used = ($today['day'] ?? NULL) === $this->today() ? (int) $today['count'] : 0;
    $total = $count === NULL ? PHP_INT_MAX : $used + $count;
    $this->state->set(self::COUNT_KEY, ['day' => $this->today(), 'count' => $total]);
  }

  /**
   * Appraises the next domains due, within today's allowance.
   *
   * @return int
   *   How many were appraised.
   */
  public function cron(): int {
    $count = min(self::PER_RUN, $this->remainingToday());
    $provider = $count ? $this->provider() : NULL;
    if (!$provider) {
      return 0;
    }
    $done = 0;
    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($this->due($count));
    foreach ($products as $product) {
      try {
        $this->appraise($product, $provider);
        $done++;
      }
      catch (ProviderException $e) {
        $this->logger->warning('Appraisals stopped for today: @error', ['@error' => $e->getMessage()]);
        $this->countToday(NULL);
        return $done;
      }
    }
    $this->countToday($done);
    return $done;
  }

  /**
   * Returns today's date, which the daily allowance is counted by.
   */
  protected function today(): string {
    return gmdate('Y-m-d', $this->time->getRequestTime());
  }

}

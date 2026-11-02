<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains_owner\Entity\DomainRenewalInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads and adds the renewals recorded for domains.
 */
class RenewalHistory {

  // An expiry date moving less than this isn't a year paid for: a correction.
  protected const MIN_MOVE = 300 * 86400;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a domain's renewals, oldest first.
   *
   * @return \Drupal\commerce_domains_owner\Entity\DomainRenewalInterface[]
   *   The renewals.
   */
  public function forProduct(ProductInterface $product): array {
    if ($product->isNew()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_domains_renewal');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('product', $product->id())
      ->sort('paid')
      ->sort('renewal_id')
      ->execute();
    /** @var \Drupal\commerce_domains_owner\Entity\DomainRenewalInterface[] $renewals */
    $renewals = $ids ? array_values($storage->loadMultiple($ids)) : [];
    return $renewals;
  }

  /**
   * Returns what a year cost in the latest renewal charged, when there is one.
   *
   * Estimated records don't count: they know no more than the cost they took.
   */
  public function latestCost(ProductInterface $product): ?Price {
    foreach (array_reverse($this->forProduct($product)) as $renewal) {
      $amount = $renewal->getAmount();
      if ($amount && !$renewal->isEstimated()) {
        return $amount->divide((string) $renewal->getYears());
      }
    }
    return NULL;
  }

  /**
   * Records the renewal the registrar's new expiry date shows, as estimated.
   *
   * A sync that finds the expiry date moved on by about a year or more means
   * someone paid. What it cost is only known from the registrar's history:
   * importing that replaces this record.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The domain, just saved by a sync.
   * @param \Drupal\commerce_price\Price|null $yearly
   *   What a year costs, if known.
   * @param int $now
   *   The time, as a Unix time.
   */
  public function recordFromSync(ProductInterface $product, ?Price $yearly, int $now): ?DomainRenewalInterface {
    $original = $product->getOriginal();
    if (!$original instanceof ProductInterface || !$product->isSyncing()
      || !$product->hasField('domain_expires') || !$product->hasField('domain_sold') || !$product->get('domain_sold')->isEmpty()
      || $original->get('domain_expires')->isEmpty() || $product->get('domain_expires')->isEmpty()) {
      return NULL;
    }
    $expires = (int) $product->get('domain_expires')->value;
    $moved = $expires - (int) $original->get('domain_expires')->value;
    if ($moved < self::MIN_MOVE) {
      return NULL;
    }
    $years = max(1, (int) round($moved / (365.25 * 86400)));
    $registrar = $product->get('domain_registrar')->target_id;
    $moved_registrar = (string) $original->get('domain_registrar')->target_id !== (string) $registrar;
    /** @var \Drupal\commerce_domains_owner\Entity\DomainRenewalInterface $renewal */
    $renewal = $this->entityTypeManager->getStorage('commerce_domains_renewal')->create([
      'product' => $product->id(),
      'paid' => $now,
      'years' => $years,
      'amount' => $yearly?->multiply((string) $years),
      'provider' => $registrar,
      'type' => $moved_registrar ? DomainRenewalInterface::TRANSFER : DomainRenewalInterface::RENEWAL,
      'estimated' => TRUE,
      'source' => 'registrar_sync',
      'external_id' => 'sync:' . $expires,
    ]);
    $renewal->save();
    return $renewal;
  }

  /**
   * Lets go of the renewals loaded, between chunks of a long run.
   */
  public function resetCache(): void {
    $this->entityTypeManager->getStorage('commerce_domains_renewal')->resetCache();
  }

}

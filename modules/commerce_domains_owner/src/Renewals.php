<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;

/**
 * Finds the domains that expire soon, and carries out the owner's decisions.
 *
 * Each domain is kept, left to expire, or not decided yet. One left to
 * expire goes off sale (its product is unpublished) a set number of days
 * before its expiry date, so nobody buys it as it lapses; keeping it again
 * puts it back on sale. Auto-renew changes at the registrar only when the
 * owner asks for it.
 */
class Renewals {

  // Expired domains stay listed this long, as most registrars still renew
  // them at the normal price for about a month.
  public const GRACE = 30 * 86400;

  // The domains taken off sale for expiring: when, keyed by product ID.
  public const OFF_SALE_KEY = 'commerce_domains_owner.off_sale';

  // Domains looked up at once.
  protected const CHUNK_SIZE = 200;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Catalog $catalog,
    protected RegistrarSync $registrarSync,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a product type has the costs trait, which keeps the decisions.
   */
  public function hasCosts(): bool {
    return isset($this->entityFieldManager->getFieldStorageDefinitions('commerce_product')['domain_renewal']);
  }

  /**
   * Returns how many days ahead a domain counts as expiring soon.
   */
  public function days(): int {
    return max(1, (int) $this->configFactory->get('commerce_domains_owner.settings')->get('expiring_days'));
  }

  /**
   * Builds the query for the domains expiring within some days.
   *
   * Soonest first. Domains that expired within the grace period count, as
   * they can still be renewed. Sold domains don't, nor domains their
   * registrar's last read of the whole account didn't find: they are gone.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   The query, or NULL when no product type sells domains.
   */
  public function query(int $days): ?QueryInterface {
    $types = $this->catalog->productTypes();
    if (!$types) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    $query = $this->entityTypeManager->getStorage('commerce_product')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $types, 'IN')
      ->condition('domain_expires', $now - self::GRACE, '>=')
      ->condition('domain_expires', $now + $days * 86400, '<=')
      ->sort('domain_expires')
      ->sort('product_id');
    if ($this->hasCosts()) {
      $query->notExists('domain_sold');
    }
    if ($gone = $this->goneIds()) {
      $query->condition('product_id', $gone, 'NOT IN');
    }
    return $query;
  }

  /**
   * Loads the domains expiring within some days, soonest first.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface[]
   *   The products, keyed by ID.
   */
  public function expiring(int $days, ?int $limit = NULL): array {
    $query = $this->query($days);
    if (!$query) {
      return [];
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    /** @var \Drupal\commerce_product\Entity\ProductInterface[] */
    return $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($query->execute());
  }

  /**
   * Returns the providers that can read registrar accounts.
   *
   * @return \Drupal\commerce_domains\Entity\ProviderInterface[]
   *   The providers, keyed by ID.
   */
  public function registrars(): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    return array_filter($providers, fn(ProviderInterface $provider) => $provider->hasCapability(RegistrarInterface::class));
  }

  /**
   * Returns the products their registrar's last whole read didn't find.
   *
   * @return int[]
   *   Product IDs.
   */
  public function goneIds(): array {
    $types = $this->catalog->productTypes();
    if (!$types) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = [];
    foreach (array_keys($this->registrars()) as $id) {
      $missing = $this->registrarSync->lastCheck((string) $id)['missing'] ?? [];
      foreach (array_chunk($missing, self::CHUNK_SIZE) as $chunk) {
        $ids += $storage->getQuery()->accessCheck(FALSE)
          ->condition('type', $types, 'IN')
          ->condition('domain_registrar', $id)
          ->condition('domain', $chunk, 'IN')
          ->execute();
      }
    }
    return array_map('intval', array_values($ids));
  }

  /**
   * Records a decision on domains.
   *
   * Domains left to expire go off sale at once when their expiry is near;
   * domains kept again go back on sale (see restore()).
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface[] $products
   *   The domains.
   * @param string|null $decision
   *   DomainCosts::KEEP or DomainCosts::EXPIRE, or NULL for not decided.
   */
  public function decide(array $products, ?string $decision): void {
    $ids = [];
    foreach ($products as $product) {
      if ($product->hasField('domain_renewal')) {
        $product->set('domain_renewal', $decision)->save();
        $ids[] = (int) $product->id();
      }
    }
    if ($decision === DomainCosts::EXPIRE) {
      $this->takeOffSale($ids);
    }
  }

  /**
   * Takes domains left to expire off sale once their expiry date is near.
   *
   * A domain someone put back on sale since is left there.
   *
   * @param int[]|null $ids
   *   Only these products, or NULL for every domain.
   *
   * @return int
   *   How many went off sale.
   */
  public function takeOffSale(?array $ids = NULL): int {
    $types = $this->catalog->productTypes();
    if ($ids === [] || !$types || !$this->hasCosts()) {
      return 0;
    }
    $days = max(0, (int) $this->configFactory->get('commerce_domains_owner.settings')->get('off_sale_days'));
    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', $types, 'IN')
      ->condition('domain_renewal', DomainCosts::EXPIRE)
      ->condition('status', TRUE)
      ->condition('domain_expires', $now + $days * 86400, '<=');
    if ($ids !== NULL) {
      $query->condition('product_id', $ids, 'IN');
    }

    $off = $this->state->get(self::OFF_SALE_KEY, []);
    $count = 0;
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($storage->loadMultiple($query->execute()) as $id => $product) {
      if (isset($off[$id])) {
        continue;
      }
      $product->setUnpublished()->save();
      $off[$id] = $now;
      $count++;
      $this->logger->notice('@domain went off sale: it is left to expire.', ['@domain' => $product->label()]);
    }
    if ($count) {
      $this->state->set(self::OFF_SALE_KEY, $off);
    }
    return $count;
  }

  /**
   * Puts a domain back on sale when it is no longer left to expire.
   *
   * Only domains taken off sale for expiring. Called before the product is
   * saved, so a decision changed on the product form counts too.
   */
  public function restore(ProductInterface $product): void {
    if ($product->isNew() || !$product->hasField('domain_renewal') || $product->get('domain_renewal')->value === DomainCosts::EXPIRE) {
      return;
    }
    $off = $this->state->get(self::OFF_SALE_KEY, []);
    if (!isset($off[$product->id()])) {
      return;
    }
    unset($off[$product->id()]);
    $this->state->set(self::OFF_SALE_KEY, $off);
    if (!$product->isPublished()) {
      $product->setPublished();
      $this->logger->notice('@domain is back on sale: it is no longer left to expire.', ['@domain' => $product->label()]);
    }
  }

  /**
   * Forgets a deleted domain.
   */
  public function forget(ProductInterface $product): void {
    $off = $this->state->get(self::OFF_SALE_KEY, []);
    if (isset($off[$product->id()])) {
      unset($off[$product->id()]);
      $this->state->set(self::OFF_SALE_KEY, $off);
    }
  }

  /**
   * Whether a domain was taken off sale for expiring.
   */
  public function isOffSale(ProductInterface $product): bool {
    return isset($this->state->get(self::OFF_SALE_KEY, [])[$product->id()]) && !$product->isPublished();
  }

  /**
   * Returns the domain's registrar, when its API can change renewal.
   */
  public function managingRegistrar(ProductInterface $product): ?ProviderInterface {
    $provider = $product->hasField('domain_registrar') ? $product->get('domain_registrar')->entity : NULL;
    return $provider instanceof ProviderInterface && $provider->hasCapability(DomainManagementInterface::class) ? $provider : NULL;
  }

  /**
   * Turns auto-renew on or off at the registrar, and records it.
   *
   * This acts on the registrar at once: call it only when the owner asks.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the registrar can't or won't change it.
   */
  public function setAutoRenew(ProductInterface $product, bool $on): void {
    $name = (string) $product->get('domain')->value;
    $provider = $this->managingRegistrar($product);
    if (!$provider) {
      throw new ProviderException(sprintf("The registrar of %s can't change renewal through its API.", $name));
    }
    $plugin = $provider->getPlugin();
    assert($plugin instanceof DomainManagementInterface);
    if (!$provider->status()) {
      throw new ProviderException(sprintf('%s is disabled.', $provider->label()));
    }
    if ($error = $plugin->getSetupError()) {
      throw new ProviderException($error);
    }
    $context = [
      '@domain' => $name,
      '@provider' => $provider->label(),
      '@change' => $on ? 'auto-renew on' : 'auto-renew off',
    ];
    try {
      $plugin->setAutoRenew($name, $on);
    }
    catch (ProviderException $e) {
      $this->logger->warning('@domain at @provider: @change failed: @error', $context + ['@error' => $e->getMessage()]);
      throw $e;
    }
    $this->logger->notice('@domain at @provider: @change.', $context);
    if ($product->hasField('domain_auto_renew')) {
      // The registrar's setting, not an edit: keep the changed time.
      $syncing = $product->isSyncing();
      $product->set('domain_auto_renew', $on)->setSyncing(TRUE)->save();
      $product->setSyncing($syncing);
    }
  }

  /**
   * Describes a domain's decision.
   */
  public static function decisionLabel(ProductInterface $product): TranslatableMarkup {
    $decision = $product->hasField('domain_renewal') ? $product->get('domain_renewal')->value : NULL;
    return match ($decision) {
      DomainCosts::KEEP => new TranslatableMarkup('Keep'),
      DomainCosts::EXPIRE => new TranslatableMarkup('Let it expire'),
      default => new TranslatableMarkup('Not decided'),
    };
  }

  /**
   * Says how far away an expiry date is: "in 3 days", "today", "2 days ago".
   */
  public static function when(int $expires, int $now): TranslatableMarkup {
    $days = (int) round(($expires - $now) / 86400);
    return match (TRUE) {
      $days > 0 => new PluralTranslatableMarkup($days, 'in 1 day', 'in @count days'),
      $days < 0 => new PluralTranslatableMarkup(-$days, '1 day ago', '@count days ago'),
      default => new TranslatableMarkup('today'),
    };
  }

  /**
   * Returns whether a domain renews automatically: NULL when unknown.
   */
  public static function autoRenews(ProductInterface $product): ?bool {
    if (!$product->hasField('domain_auto_renew') || $product->get('domain_auto_renew')->isEmpty()) {
      return NULL;
    }
    return (bool) $product->get('domain_auto_renew')->value;
  }

}

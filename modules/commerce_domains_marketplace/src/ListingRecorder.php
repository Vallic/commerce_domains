<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Records what each sync found and did, one row per domain and marketplace.
 *
 * Syncs compare the catalog with the marketplace every time; the rows are
 * for people, on the domain overview and the listings report. Sales are the
 * exception: the record of a sale is what keeps a sync from acting on it
 * twice.
 */
class ListingRecorder {

  public const TABLE = 'commerce_domains_listing';

  // Listed as the catalog wants.
  public const LISTED = 'listed';

  // To be added, changed or removed by the next push.
  public const OUTDATED = 'outdated';

  // Left alone, e.g. curated by the marketplace, or a sale that was over
  // here before the marketplace caught up.
  public const LOCKED = 'locked';

  // The marketplace sold it, or is selling it: off sale here, and left alone
  // there.
  public const SOLD = 'sold';

  // The marketplace refused the last push.
  public const FAILED = 'failed';

  // Not listed, and not wanted there.
  public const UNLISTED = 'unlisted';

  // Listed, but the plugin leaves it to the marketplace.
  public const UNMANAGED = 'unmanaged';

  // Domains written or looked up at once.
  protected const CHUNK_SIZE = 500;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the table's schema.
   */
  public static function schema(): array {
    return [
      'description' => "Each catalog domain's listing on each marketplace, as the last sync found it.",
      'fields' => [
        'provider' => [
          'description' => 'The provider ID.',
          'type' => 'varchar_ascii',
          'length' => 32,
          'not null' => TRUE,
        ],
        'domain' => [
          'description' => 'The domain, normalized.',
          'type' => 'varchar_ascii',
          'length' => 253,
          'not null' => TRUE,
        ],
        'product_id' => [
          'description' => "The domain's product, when the catalog has it.",
          'type' => 'int',
          'unsigned' => TRUE,
        ],
        'status' => [
          'description' => 'The listing status, e.g. listed or failed.',
          'type' => 'varchar_ascii',
          'length' => 16,
          'not null' => TRUE,
        ],
        'note' => [
          'description' => 'What is pending, why it is locked, or the error.',
          'type' => 'text',
          'size' => 'normal',
        ],
        'listing' => [
          'description' => 'The listing as JSON: the one wanted, or the one found when it is not ours to change.',
          'type' => 'text',
          'size' => 'normal',
        ],
        'checked' => [
          'description' => 'When a sync last compared it with the marketplace.',
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'pushed' => [
          'description' => 'When it was last written to the marketplace.',
          'type' => 'int',
        ],
        'changed' => [
          'description' => 'When the status, note or listing last changed.',
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'sold' => [
          'description' => 'When a sync found the marketplace selling the domain; kept while the listing shows the sale.',
          'type' => 'int',
        ],
      ] + self::statsSchema(),
      'primary key' => ['provider', 'domain'],
      'indexes' => [
        'product_id' => ['product_id'],
        'status' => ['status'],
      ],
    ];
  }

  /**
   * Returns the table's columns for the interest in each listing.
   */
  public static function statsSchema(): array {
    $count = fn(string $description) => ['description' => $description, 'type' => 'int', 'unsigned' => TRUE];
    return [
      'views' => $count('Views of the listing, as the marketplace counts them.'),
      'leads' => $count('Inquiries and other leads.'),
      'offers' => $count('Offers made.'),
      'saves' => $count('Times buyers saved the listing.'),
      'stats_days' => $count('The days the counts cover, back from when they were read; empty for all time.'),
      'stats_checked' => [
        'description' => 'When the counts were last read.',
        'type' => 'int',
      ],
    ];
  }

  /**
   * Returns the statuses, labelled for people.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by status.
   */
  public static function statusLabels(): array {
    return [
      self::LISTED => new TranslatableMarkup('Listed'),
      self::OUTDATED => new TranslatableMarkup('Out of date'),
      self::LOCKED => new TranslatableMarkup('Locked'),
      self::SOLD => new TranslatableMarkup('Sold'),
      self::FAILED => new TranslatableMarkup('Failed'),
      self::UNLISTED => new TranslatableMarkup('Not listed'),
      self::UNMANAGED => new TranslatableMarkup('Left to the marketplace'),
    ];
  }

  /**
   * Records what a sync found, before anything is pushed.
   */
  public function recordCheck(ProviderInterface $provider, SyncPlan $plan): void {
    $plugin = MarketplaceSettings::plugin($provider);
    $describe = fn(string $field, mixed $value) => $plugin->describeValue($field, $value);
    $removal_off = !MarketplaceSettings::deletesUnlisted($provider);
    $delete = array_flip($plan->delete);
    $excluded = array_flip($plan->excluded);

    $rows = [];
    foreach ([...$plan->managed, ...$plan->removed] as $name) {
      if (isset($plan->sales[$name])) {
        $rows[$name] = $this->saleRow($plan, $name);
        continue;
      }
      $current = $plan->current[$name] ?? NULL;
      $row = [
        'product_id' => $plan->catalog[$name] ?? NULL,
        'note' => NULL,
        'listing' => $plan->wanted[$name] ?? NULL,
        // A listing that shows no sale ends the record of one.
        'sold' => NULL,
      ];
      if (isset($plan->locked[$name])) {
        $row['status'] = self::LOCKED;
        $row['note'] = $plan->locked[$name];
        $row['listing'] = $current;
      }
      elseif (isset($plan->create[$name])) {
        $row['status'] = self::OUTDATED;
        $row['note'] = (string) new TranslatableMarkup('Not listed yet.');
      }
      elseif (isset($plan->update[$name])) {
        $changes = array_map(
          fn(string $field) => $field . ' ' . $describe($field, $current[$field] ?? NULL) . ' → ' . $describe($field, $plan->update[$name][$field]),
          $plan->changes[$name],
        );
        $row['status'] = self::OUTDATED;
        $row['note'] = implode(', ', $changes);
      }
      elseif (isset($delete[$name])) {
        $row['status'] = self::OUTDATED;
        $row['note'] = (string) match (TRUE) {
          !isset($plan->catalog[$name]) && $removal_off => new TranslatableMarkup('No longer in the catalog; removal is turned off.'),
          !isset($plan->catalog[$name]) => new TranslatableMarkup('No longer in the catalog; to be removed.'),
          isset($excluded[$name]) && $removal_off => new TranslatableMarkup('Not to be listed here; removal is turned off.'),
          isset($excluded[$name]) => new TranslatableMarkup('Not to be listed here; to be removed.'),
          $removal_off => new TranslatableMarkup('No longer for sale; removal is turned off.'),
          default => new TranslatableMarkup('No longer for sale; to be removed.'),
        };
        $row['listing'] = $current;
      }
      else {
        $row['status'] = isset($plan->wanted[$name]) ? self::LISTED : self::UNLISTED;
      }
      $rows[$name] = $row;
    }
    foreach ($plan->unmanaged as $name) {
      // A sale counts on listings the plugin leaves to the marketplace too.
      if (isset($plan->sales[$name])) {
        $rows[$name] = $this->saleRow($plan, $name);
      }
      elseif (isset($plan->catalog[$name])) {
        $rows[$name] = [
          'product_id' => $plan->catalog[$name],
          'status' => self::UNMANAGED,
          'note' => NULL,
          'listing' => $plan->current[$name],
          'sold' => NULL,
        ];
      }
    }

    $this->write($provider->id(), $rows, $plan->full);
  }

  /**
   * Builds the row of a listing that shows a sale.
   *
   * Sold while the domain is off sale here. Once it is back on sale, the
   * sale is over here but not yet on the marketplace, so it stays locked.
   * The time the sale was found is kept either way.
   */
  protected function saleRow(SyncPlan $plan, string $name): array {
    $sold = in_array($name, $plan->newSales, TRUE) || !isset($plan->available[$name]);
    return [
      'product_id' => $plan->catalog[$name] ?? NULL,
      'status' => $sold ? self::SOLD : self::LOCKED,
      'note' => $plan->sales[$name],
      'listing' => $plan->current[$name],
    ];
  }

  /**
   * Returns whether a sale a listing shows is not on record yet.
   *
   * It is on record while the row says sold, and, once the domain is back on
   * sale here, while the marketplace shows the same status. A new status
   * then, e.g. a hold that turned into a sale, is a sale again.
   *
   * @param object|null $row
   *   The domain's row on that marketplace, if any.
   * @param string $status
   *   The marketplace's status for the sale.
   */
  public static function isNewSale(?object $row, string $status): bool {
    return !$row || ($row->status !== self::SOLD && (($row->sold ?? NULL) === NULL || $row->note !== $status));
  }

  /**
   * Records a sale, unless another sync just did.
   *
   * Takes the same test as isNewSale() in one statement, so two syncs that
   * find the same sale act on it once.
   *
   * @param string $provider_id
   *   The provider ID.
   * @param string $name
   *   The domain.
   * @param int|null $product_id
   *   The domain's product, when the catalog has it.
   * @param string $status
   *   The marketplace's status for the sale.
   * @param array $listing
   *   The listing showing it.
   *
   * @return bool
   *   Whether this call recorded it.
   */
  public function claimSale(string $provider_id, string $name, ?int $product_id, string $status, array $listing): bool {
    $now = $this->time->getCurrentTime();
    $fields = [
      'product_id' => $product_id,
      'status' => self::SOLD,
      'note' => $status,
      'listing' => json_encode($listing),
      'checked' => $now,
      'changed' => $now,
      'sold' => $now,
    ];
    $query = $this->database->update(self::TABLE)
      ->fields($fields)
      ->condition('provider', $provider_id)
      ->condition('domain', $name)
      ->condition('status', self::SOLD, '<>');
    $query->condition($query->orConditionGroup()
      ->isNull('sold')
      ->isNull('note')
      ->condition('note', $status, '<>'));
    if ($query->execute()) {
      return TRUE;
    }
    if ($this->load($provider_id, [$name])) {
      return FALSE;
    }
    try {
      $this->database->insert(self::TABLE)
        ->fields(['provider' => $provider_id, 'domain' => $name] + $fields)
        ->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      // Another sync recorded it in between.
      return FALSE;
    }
  }

  /**
   * Ends the recorded sales of a domain that is back on sale here.
   *
   * A sale that fell through: the next syncs list the domain again. Listings
   * that still show the sale stay locked until the marketplace catches up,
   * and the same status isn't taken for a new sale.
   */
  public function relisted(string $name): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => self::LOCKED, 'changed' => $this->time->getCurrentTime()])
      ->condition('domain', $name)
      ->condition('status', self::SOLD)
      ->execute();
  }

  /**
   * Returns a provider's rows for some domains.
   *
   * @param string $provider_id
   *   The provider ID.
   * @param string[] $names
   *   The domains.
   *
   * @return array<string, object>
   *   Rows keyed by domain; domains without one are missing.
   */
  public function rows(string $provider_id, array $names): array {
    return $names ? $this->load($provider_id, array_values($names)) : [];
  }

  /**
   * Records what a push did.
   *
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The provider.
   * @param \Drupal\commerce_domains_marketplace\SyncPlan $plan
   *   The plan that was pushed.
   * @param array<string, string> $errors
   *   The marketplace's errors, keyed by domain.
   * @param bool $deleted
   *   Whether the listings in $plan->delete were removed.
   *
   * @return array<string, string>
   *   The errors that are new: not on record already as the same failure.
   */
  public function recordPush(ProviderInterface $provider, SyncPlan $plan, array $errors, bool $deleted): array {
    $now = $this->time->getCurrentTime();
    $old = $this->rows((string) $provider->id(), array_map('strval', array_keys($errors)));
    $new = array_filter($errors, fn(mixed $error, int|string $name) => !isset($old[$name]) || $old[$name]->status !== self::FAILED || $old[$name]->note !== (string) $error, ARRAY_FILTER_USE_BOTH);
    $rows = [];
    foreach ($plan->create + $plan->update as $name => $listing) {
      $rows[$name] = isset($errors[$name])
        ? ['status' => self::FAILED, 'note' => $errors[$name], 'listing' => $listing]
        : ['status' => self::LISTED, 'note' => NULL, 'listing' => $listing, 'pushed' => $now];
    }
    if ($deleted) {
      foreach ($plan->delete as $name) {
        $rows[$name] = isset($errors[$name])
          ? ['status' => self::FAILED, 'note' => $errors[$name], 'listing' => $plan->current[$name] ?? NULL]
          : ['status' => self::UNLISTED, 'note' => NULL, 'listing' => NULL, 'pushed' => $now];
      }
    }
    foreach ($rows as $name => &$row) {
      $row['product_id'] = $plan->catalog[$name] ?? NULL;
    }
    $this->write($provider->id(), $rows, FALSE, TRUE);
    return $new;
  }

  /**
   * Returns the domains whose listing interest is due a read.
   *
   * Those never read first, then the oldest. Rows of domains that aren't
   * listed there are skipped.
   *
   * @param string $provider_id
   *   The provider ID.
   * @param int $before
   *   Counts read since then are fresh.
   * @param int $limit
   *   The most domains returned.
   *
   * @return string[]
   *   The domains.
   */
  public function statsDue(string $provider_id, int $before, int $limit): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['domain'])
      ->condition('provider', $provider_id)
      ->condition('status', self::UNLISTED, '<>');
    $query->condition($query->orConditionGroup()
      ->isNull('stats_checked')
      ->condition('stats_checked', $before, '<'));
    // Never read (NULL, as 0) first.
    $query->addExpression('COALESCE(stats_checked, 0)', 'read_at');
    return $query->orderBy('read_at')
      ->orderBy('domain')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();
  }

  /**
   * Records the interest in listings.
   *
   * @param string $provider_id
   *   The provider ID.
   * @param string[] $names
   *   The domains read; those without stats get empty counts.
   * @param array<string, \Drupal\commerce_domains\ListingStats> $stats
   *   What the marketplace reported, keyed by domain.
   */
  public function recordStats(string $provider_id, array $names, array $stats): void {
    $now = $this->time->getCurrentTime();
    foreach ($names as $name) {
      $found = $stats[$name] ?? NULL;
      $this->database->update(self::TABLE)
        ->fields([
          'views' => $found?->views,
          'leads' => $found?->leads,
          'offers' => $found?->offers,
          'saves' => $found?->saves,
          'stats_days' => $found?->days,
          'stats_checked' => $now,
        ])
        ->condition('provider', $provider_id)
        ->condition('domain', $name)
        ->execute();
    }
  }

  /**
   * Returns the rows of some products, for the domain overview.
   *
   * @param int[] $product_ids
   *   The product IDs.
   *
   * @return array<int, array<string, object>>
   *   Rows keyed by product ID, then by provider ID.
   */
  public function forProducts(array $product_ids): array {
    $rows = [];
    foreach (array_chunk(array_values(array_unique($product_ids)), self::CHUNK_SIZE) as $chunk) {
      $result = $this->database->select(self::TABLE, 'l')
        ->fields('l')
        ->condition('product_id', $chunk, 'IN')
        ->orderBy('provider')
        ->execute();
      foreach ($result as $row) {
        $rows[(int) $row->product_id][$row->provider] = $row;
      }
    }
    return $rows;
  }

  /**
   * Returns the domains recorded for a provider: the names syncs managed.
   *
   * @return string[]
   *   The domains.
   */
  public function names(string $provider_id): array {
    return $this->database->select(self::TABLE, 'l')
      ->fields('l', ['domain'])
      ->condition('provider', $provider_id)
      ->execute()
      ->fetchCol();
  }

  /**
   * Removes a provider's rows, e.g. when the provider is deleted.
   */
  public function deleteProvider(string $provider_id): void {
    $this->database->delete(self::TABLE)->condition('provider', $provider_id)->execute();
  }

  /**
   * Unlinks a deleted product's rows; the next sync removes or updates them.
   */
  public function unlinkProduct(int $product_id): void {
    $this->database->update(self::TABLE)
      ->fields(['product_id' => NULL])
      ->condition('product_id', $product_id)
      ->execute();
  }

  /**
   * Writes rows, touching only the ones that changed.
   *
   * @param string $provider_id
   *   The provider ID.
   * @param array<string, array> $rows
   *   Rows keyed by domain: status, note, listing and product_id, plus
   *   pushed after a push, and sold as NULL to end the record of a sale.
   * @param bool $full
   *   Whether the rows cover the whole catalog and portfolio, so rows for
   *   anything else are stale.
   * @param bool $pushed
   *   Whether the rows come from a push rather than a check.
   */
  protected function write(string $provider_id, array $rows, bool $full, bool $pushed = FALSE): void {
    $now = $this->time->getCurrentTime();
    $existing = $this->load($provider_id, $full ? NULL : array_keys($rows));

    $touched = [];
    $transaction = $this->database->startTransaction();
    foreach ($rows as $name => $row) {
      $old = $existing[$name] ?? NULL;
      $listing = $row['listing'] === NULL ? NULL : json_encode($row['listing']);
      // A domain that isn't listed and never was needs no row.
      if (!$old && $row['status'] === self::UNLISTED && !isset($row['pushed'])) {
        continue;
      }
      // A failure stays on record until the push it failed is made.
      if ($old && $old->status === self::FAILED && $row['status'] === self::OUTDATED && $old->listing === $listing) {
        $row['status'] = self::FAILED;
        $row['note'] = $old->note;
      }
      $fields = [
        'product_id' => $row['product_id'],
        'status' => $row['status'],
        'note' => $row['note'],
        'listing' => $listing,
      ];
      // Rows without the key keep the time their sale was found.
      $same_sold = TRUE;
      if (array_key_exists('sold', $row)) {
        $fields['sold'] = $row['sold'];
        $old_sold = $old->sold ?? NULL;
        $same_sold = $old && ($old_sold === NULL ? $row['sold'] === NULL : (int) $old_sold === (int) $row['sold']);
      }
      $same = $old
        && (int) $old->product_id === (int) $fields['product_id']
        && $old->status === $fields['status']
        && $old->note === $fields['note']
        && $old->listing === $fields['listing']
        && $same_sold;
      if ($same && !isset($row['pushed'])) {
        $touched[] = $name;
        continue;
      }
      $fields += $same ? [] : ['changed' => $now];
      if (!$pushed) {
        $fields['checked'] = $now;
      }
      if (isset($row['pushed'])) {
        $fields['pushed'] = $row['pushed'];
      }
      $keys = ['provider' => $provider_id, 'domain' => $name];
      $this->database->merge(self::TABLE)
        ->keys($keys)
        ->insertFields($keys + $fields + ['checked' => $now, 'changed' => $now])
        ->updateFields($fields)
        ->execute();
    }
    if (!$pushed) {
      foreach (array_chunk($touched, self::CHUNK_SIZE) as $chunk) {
        $this->database->update(self::TABLE)
          ->fields(['checked' => $now])
          ->condition('provider', $provider_id)
          ->condition('domain', $chunk, 'IN')
          ->execute();
      }
    }
    // After a whole check, rows for names neither side has any more go.
    if ($full && ($stale = array_keys(array_diff_key($existing, $rows)))) {
      foreach (array_chunk($stale, self::CHUNK_SIZE) as $chunk) {
        $this->database->delete(self::TABLE)
          ->condition('provider', $provider_id)
          ->condition('domain', $chunk, 'IN')
          ->execute();
      }
    }
    unset($transaction);
  }

  /**
   * Loads a provider's rows.
   *
   * @return array<string, object>
   *   Rows keyed by domain.
   */
  protected function load(string $provider_id, ?array $names): array {
    $rows = [];
    $chunks = $names === NULL ? [NULL] : array_chunk($names, self::CHUNK_SIZE);
    foreach ($chunks as $chunk) {
      $query = $this->database->select(self::TABLE, 'l')->fields('l')->condition('provider', $provider_id);
      if ($chunk !== NULL) {
        $query->condition('domain', $chunk, 'IN');
      }
      foreach ($query->execute() as $row) {
        $rows[$row->domain] = $row;
      }
    }
    return $rows;
  }

}

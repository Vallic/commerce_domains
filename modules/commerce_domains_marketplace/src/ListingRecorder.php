<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Records what each sync found and did, one row per domain and marketplace.
 *
 * Syncs never read these rows back: they compare the catalog with the
 * marketplace every time. The rows are for people, on the domain overview
 * and the listings report.
 */
class ListingRecorder {

  public const TABLE = 'commerce_domains_listing';

  // Listed as the catalog wants.
  public const LISTED = 'listed';

  // To be added, changed or removed by the next push.
  public const OUTDATED = 'outdated';

  // The marketplace is selling it, so it is left alone.
  public const LOCKED = 'locked';

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
      ],
      'primary key' => ['provider', 'domain'],
      'indexes' => [
        'product_id' => ['product_id'],
        'status' => ['status'],
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
      self::LOCKED => new TranslatableMarkup('Mid-sale'),
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

    $rows = [];
    foreach ([...$plan->managed, ...$plan->removed] as $name) {
      $current = $plan->current[$name] ?? NULL;
      $row = ['product_id' => $plan->catalog[$name] ?? NULL, 'note' => NULL, 'listing' => $plan->wanted[$name] ?? NULL];
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
        $row['note'] = (string) ($removal_off
          ? new TranslatableMarkup('No longer for sale; removal is turned off.')
          : new TranslatableMarkup('No longer for sale; to be removed.'));
        $row['listing'] = $current;
      }
      else {
        $row['status'] = isset($plan->wanted[$name]) ? self::LISTED : self::UNLISTED;
      }
      $rows[$name] = $row;
    }
    foreach ($plan->unmanaged as $name) {
      if (isset($plan->catalog[$name])) {
        $rows[$name] = [
          'product_id' => $plan->catalog[$name],
          'status' => self::UNMANAGED,
          'note' => NULL,
          'listing' => $plan->current[$name],
        ];
      }
    }

    $this->write($provider->id(), $rows, $plan->full);
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
   */
  public function recordPush(ProviderInterface $provider, SyncPlan $plan, array $errors, bool $deleted): void {
    $now = $this->time->getCurrentTime();
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
   *   pushed after a push.
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
      $same = $old
        && (int) $old->product_id === (int) $fields['product_id']
        && $old->status === $fields['status']
        && $old->note === $fields['note']
        && $old->listing === $fields['listing'];
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

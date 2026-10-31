<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Keeps a domain's DNS records as they were before each change.
 *
 * Writing a zone replaces it at the registrar, and a mistake takes a site
 * or its mail offline, so the zone there before is kept, and can be put
 * back from the DNS tab.
 */
class DnsHistory {

  // The key-value collection, keyed by domain.
  public const COLLECTION = 'commerce_domains.dns_history';

  // How many earlier zones are kept for each domain.
  public const KEEP = 10;

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Keeps a zone as it was before a change.
   *
   * @param string $name
   *   The domain.
   * @param string $provider
   *   The ID of the registrar the zone was read from.
   * @param \Drupal\commerce_domains\DnsRecord[] $records
   *   The records.
   */
  public function keep(string $name, string $provider, array $records): void {
    $history = $this->list($name);
    array_unshift($history, [
      'time' => $this->time->getRequestTime(),
      'provider' => $provider,
      'uid' => (int) $this->currentUser->id(),
      'records' => array_map(static fn(DnsRecord $record) => $record->toArray(), array_values($records)),
    ]);
    $this->store()->set($name, array_slice($history, 0, self::KEEP));
  }

  /**
   * Lists the zones kept for a domain, the latest first.
   *
   * @return array<int, array{time: int, provider: string, uid: int, records: array<int, array<string, mixed>>}>
   *   The kept zones.
   */
  public function list(string $name): array {
    return (array) $this->store()->get($name, []);
  }

  /**
   * Returns the records of a kept zone.
   *
   * @return \Drupal\commerce_domains\DnsRecord[]
   *   The records, or an empty array when there is no such zone.
   */
  public function records(string $name, int $index): array {
    $zone = $this->list($name)[$index] ?? NULL;
    return $zone ? array_map(DnsRecord::fromArray(...), $zone['records']) : [];
  }

  /**
   * Returns the key-value store.
   */
  protected function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}

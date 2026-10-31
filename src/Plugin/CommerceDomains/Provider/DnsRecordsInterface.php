<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

/**
 * Capability: edits the DNS records of domains using the registrar's DNS.
 *
 * Records only apply while the domain uses the registrar's own nameservers;
 * with others, the records are wherever those nameservers are. Writing acts
 * at once, so callers show the owner what changes first, and keep what was
 * there so it can be put back.
 */
interface DnsRecordsInterface extends RegistrarInterface {

  /**
   * Fetches a domain's DNS records.
   *
   * @return \Drupal\commerce_domains\DnsRecord[]|null
   *   The records, or NULL when the domain does not use the registrar's DNS.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function getDnsRecords(string $name): ?array;

  /**
   * Replaces a domain's DNS records with these.
   *
   * Where the registrar's API writes record by record, only what differs
   * from the records there now is written.
   *
   * @param string $name
   *   The normalized domain.
   * @param \Drupal\commerce_domains\DnsRecord[] $records
   *   Every record the domain should have.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function setDnsRecords(string $name, array $records): void;

  /**
   * Returns the record types the registrar's DNS takes.
   *
   * @return array<string, string|\Stringable>
   *   Labels keyed by upper-case type, the standard ones first, then the
   *   registrar's own, such as URL forwarding.
   */
  public function dnsRecordTypes(): array;

}

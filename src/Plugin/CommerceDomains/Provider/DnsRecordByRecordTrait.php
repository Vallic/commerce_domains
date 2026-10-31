<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\ProviderException;

/**
 * Replaces a zone at a registrar whose API writes one record at a time.
 *
 * Reads the zone, then deletes what goes, creates what comes and changes
 * the TTL of what stays with a new one, so records that stay are never
 * touched. Deletions go first: a replaced CNAME, or a TXT that changed,
 * would otherwise clash with the record replacing it.
 */
trait DnsRecordByRecordTrait {

  /**
   * {@inheritdoc}
   */
  public function setDnsRecords(string $name, array $records): void {
    $current = $this->getDnsRecords($name);
    if ($current === NULL) {
      throw new ProviderException(sprintf('%s does not use the DNS of this registrar, so its records are not here.', $name));
    }
    $diff = DnsRecord::diff($current, $records);
    // Everything written is checked before anything is, so a refused
    // record leaves the zone as it was. Only new records need a type
    // offered here: those of other types already there stay as they are.
    foreach ($diff['create'] as $record) {
      if (!isset($this->dnsRecordTypes()[$record->type])) {
        throw new ProviderException(sprintf('This DNS has no %s records, for %s.', $record->type, $record->fqdn($name)));
      }
    }
    foreach ([...$diff['create'], ...$diff['ttl']] as $record) {
      if ($problem = $this->dnsRecordProblem($record)) {
        throw new ProviderException(sprintf('%s: %s', $record->fqdn($name), $problem));
      }
    }
    foreach ($diff['delete'] as $record) {
      $this->deleteDnsRecord($name, $record);
    }
    foreach ($diff['create'] as $record) {
      $this->createDnsRecord($name, $record);
    }
    foreach ($diff['ttl'] as $record) {
      $this->updateDnsRecord($name, $record);
    }
  }

  /**
   * Returns why the registrar would refuse a record, if it would.
   */
  protected function dnsRecordProblem(DnsRecord $record): ?string {
    return NULL;
  }

  /**
   * Creates one record.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  abstract protected function createDnsRecord(string $name, DnsRecord $record): void;

  /**
   * Deletes one record, as read from the registrar.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  abstract protected function deleteDnsRecord(string $name, DnsRecord $record): void;

  /**
   * Changes the TTL of one record, as read, with the TTL it should have.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  abstract protected function updateDnsRecord(string $name, DnsRecord $record): void;

}

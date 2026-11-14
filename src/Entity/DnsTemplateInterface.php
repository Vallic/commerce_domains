<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A set of DNS records to give domains, at whichever registrar they are.
 *
 * Values may hold {domain}, which becomes the domain the records are for.
 */
interface DnsTemplateInterface extends ConfigEntityInterface {

  // The most records a template holds: what Dynadot takes on a domain itself.
  public const MAX_RECORDS = 20;

  // The longest note a record takes.
  public const NOTE_LENGTH = 255;

  // What becomes the domain in a record's value.
  public const PLACEHOLDER = '{domain}';

  // A URL redirect, whichever registrar: it becomes the first of
  // REDIRECT_TYPES the registrar's DNS takes.
  public const REDIRECT = 'REDIRECT';

  // Registrars' redirect types, the temporary (302) ones first: Dynadot's
  // forward, GNAME's and Namecheap's URL record, Namecheap's 301.
  public const REDIRECT_TYPES = ['FORWARD', 'URL', 'URL301'];

  /**
   * Returns the records as stored.
   *
   * @return array<int, array{type: string, host: string, value: string, priority: int|null, ttl: int|null, note: string|null}>
   *   The records; the note is what the record is for, never written to
   *   the registrar.
   */
  public function getRecords(): array;

  /**
   * Sets the records.
   *
   * @param array<int, array<string, mixed>> $records
   *   Records with type, host, value and, optionally, priority, ttl and a
   *   note on what the record is for.
   *
   * @throws \InvalidArgumentException
   *   When there are more than MAX_RECORDS.
   */
  public function setRecords(array $records): static;

  /**
   * Returns the records for a domain, with {domain} filled in.
   *
   * @return \Drupal\commerce_domains\DnsRecord[]
   *   The records.
   */
  public function recordsFor(string $name): array;

}

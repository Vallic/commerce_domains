<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

/**
 * What an import did, or would do in a dry run.
 *
 * Batches carry one report from chunk to chunk, so it is plain data.
 */
final class ImportReport {

  /**
   * Domains added to the catalog.
   *
   * @var string[]
   */
  public array $created = [];

  /**
   * Domains in the catalog whose price or sale terms changed.
   *
   * @var string[]
   */
  public array $updated = [];

  /**
   * Domains left as they are, with the reason, keyed by domain.
   *
   * @var array<string, string>
   */
  public array $skipped = [];

  /**
   * Rows that could not be imported.
   *
   * @var array<int, array{line: int, domain: string, message: string}>
   */
  public array $errors = [];

  /**
   * Things to look at that didn't stop a row, with how often they came up.
   *
   * @var array<string, int>
   */
  public array $notes = [];

  /**
   * The line each domain was first seen on, to catch repeats.
   *
   * @var array<string, int>
   */
  public array $seen = [];

  public function __construct(
    // Whether nothing was saved.
    public readonly bool $dryRun = FALSE,
  ) {}

  /**
   * Records a row that could not be imported.
   */
  public function error(int $line, string $domain, string $message): void {
    $this->errors[] = ['line' => $line, 'domain' => $domain, 'message' => $message];
  }

  /**
   * Records something to look at, counting repeats.
   */
  public function note(string $message): void {
    $this->notes[$message] = ($this->notes[$message] ?? 0) + 1;
  }

  /**
   * Returns how many rows were handled, errors included.
   */
  public function processed(): int {
    return count($this->created) + count($this->updated) + count($this->skipped) + count($this->errors);
  }

}

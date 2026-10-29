<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import\Event;

use Drupal\commerce_domains_import\ImportOptions;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Lets a site map its own columns onto a domain being imported.
 *
 * Dispatched for every row that creates or updates a domain, once the
 * import's own columns are set and before anything is saved, in dry runs
 * too. Subscribers change the product and its variation, or refuse the row.
 */
final class DomainImportEvent extends Event {

  // The event name.
  public const NAME = 'commerce_domains_import.row';

  /**
   * Why a subscriber refused the row, if one did.
   */
  protected ?string $error = NULL;

  public function __construct(
    // The row as given: CSV cells keyed by their header as written, or the
    // domain and price of a pasted line.
    public readonly array $row,
    // The domain, unsaved: new, or loaded from the catalog with the row's
    // values set.
    public readonly ProductInterface $product,
    public readonly ProductVariationInterface $variation,
    // Whether the domain is new to the catalog.
    public readonly bool $isNew,
    public readonly ImportOptions $options,
    // The line the row starts on; 0 for a domain from a registrar account.
    public readonly int $line = 0,
  ) {}

  /**
   * Returns a cell by its header, matched as the import's own columns are.
   *
   * "TLDs Taken", "tlds taken" and "tlds_taken" all find a TLDs Taken
   * column.
   *
   * @return string|null
   *   The cell, or NULL when the row has no such column or it is empty.
   */
  public function value(string $header): ?string {
    $key = ImportParser::key($header);
    foreach ($this->row as $name => $value) {
      if (ImportParser::key((string) $name) === $key) {
        $value = trim((string) $value);
        return $value === '' ? NULL : $value;
      }
    }
    return NULL;
  }

  /**
   * Refuses the row: it is reported with this message and not saved.
   */
  public function setError(string $message): void {
    $this->error = $message;
  }

  /**
   * Returns why the row was refused, or NULL.
   */
  public function getError(): ?string {
    return $this->error;
  }

}

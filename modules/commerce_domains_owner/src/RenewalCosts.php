<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains_owner\Entity\RenewalCost;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads and sets the table of renewal costs by extension and registrar.
 *
 * A domain takes its registrar's cost for the longest extension it ends with,
 * else the general cost for the longest it ends with.
 */
class RenewalCosts {

  /**
   * The costs read so far: a report asks for hundreds of domains.
   *
   * @var array<string, array<string, \Drupal\commerce_price\Price>>|null
   */
  protected ?array $costs = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Returns the default yearly cost of a domain.
   *
   * @param string $name
   *   The domain's name.
   * @param string|null $registrar
   *   The ID of its registrar, if known.
   */
  public function forDomain(string $name, ?string $registrar = NULL): ?Price {
    return $this->atRegistrar($name, $registrar) ?? self::longest($this->costs()[''] ?? [], $name);
  }

  /**
   * Returns a registrar's own yearly cost for a domain.
   */
  public function atRegistrar(string $name, ?string $registrar): ?Price {
    return $registrar === NULL ? NULL : self::longest($this->costs()[$registrar] ?? [], $name);
  }

  /**
   * Returns the row of an extension and registrar.
   */
  public function find(string $tld, ?string $registrar): ?RenewalCost {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_renewal_cost');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('tld', RenewalCost::normalize($tld))->range(0, 1);
    if ($registrar === NULL) {
      $query->notExists('provider');
    }
    else {
      $query->condition('provider', $registrar);
    }
    $ids = $query->execute();
    /** @var \Drupal\commerce_domains_owner\Entity\RenewalCost|null $cost */
    $cost = $ids ? $storage->load(reset($ids)) : NULL;
    return $cost;
  }

  /**
   * Adds the row of an extension and registrar, or changes its cost.
   */
  public function set(string $tld, ?string $registrar, Price $price): RenewalCost {
    $cost = $this->find($tld, $registrar);
    if (!$cost) {
      /** @var \Drupal\commerce_domains_owner\Entity\RenewalCost $cost */
      $cost = $this->entityTypeManager->getStorage('commerce_domains_renewal_cost')->create([
        'tld' => RenewalCost::normalize($tld),
        'provider' => $registrar,
      ]);
    }
    $cost->set('cost', $price);
    $cost->save();
    return $cost;
  }

  /**
   * Returns the extensions of the catalog's domains.
   *
   * @return string[]
   *   Extensions such as com and co.uk, sorted.
   */
  public function catalogExtensions(): array {
    if (!isset($this->entityFieldManager->getFieldMap()['commerce_product']['domain_extension'])) {
      return [];
    }
    // An aggregate query gives a row per group, not entity IDs.
    /** @var array<int, array<string, string|null>> $result */
    $result = $this->entityTypeManager->getStorage('commerce_product')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->exists('domain_extension')
      ->groupBy('domain_extension.value')
      ->execute();
    $extensions = [];
    foreach ($result as $row) {
      $extension = strtolower((string) ($row['domain_extension_value'] ?? ''));
      if ($extension !== '') {
        $extensions[] = $extension;
      }
    }
    sort($extensions);
    return array_values(array_unique($extensions));
  }

  /**
   * Returns the catalog's extensions no row covers, at any registrar.
   *
   * @return string[]
   *   The extensions.
   */
  public function missing(): array {
    $missing = [];
    foreach ($this->catalogExtensions() as $extension) {
      $covered = FALSE;
      foreach ($this->costs() as $costs) {
        $covered = $covered || self::longest($costs, 'x.' . $extension) !== NULL;
      }
      if (!$covered) {
        $missing[] = $extension;
      }
    }
    return $missing;
  }

  /**
   * Forgets the costs read, after one changed.
   */
  public function resetCache(): void {
    $this->costs = NULL;
  }

  /**
   * Returns the costs by registrar, '' for any.
   *
   * @return array<string, array<string, \Drupal\commerce_price\Price>>
   *   Prices keyed by registrar ID, then extension.
   */
  protected function costs(): array {
    if ($this->costs === NULL) {
      $this->costs = [];
      /** @var \Drupal\commerce_domains_owner\Entity\RenewalCost $row */
      foreach ($this->entityTypeManager->getStorage('commerce_domains_renewal_cost')->loadMultiple() as $row) {
        $price = $row->getCost();
        if ($price) {
          $this->costs[(string) $row->getProviderId()][$row->getTld()] = $price;
        }
      }
    }
    return $this->costs;
  }

  /**
   * Returns the cost of the longest extension that a domain ends with.
   *
   * So co.uk before uk.
   *
   * @param array<string, \Drupal\commerce_price\Price> $costs
   *   Prices keyed by extension.
   * @param string $name
   *   The domain's name.
   */
  protected static function longest(array $costs, string $name): ?Price {
    $parts = explode('.', strtolower($name));
    array_shift($parts);
    while ($parts) {
      $tld = implode('.', $parts);
      if (isset($costs[$tld])) {
        return $costs[$tld];
      }
      array_shift($parts);
    }
    return NULL;
  }

}

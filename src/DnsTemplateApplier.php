<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_domains\Entity\DnsTemplateInterface;
use Drupal\commerce_domains\Form\DomainDnsForm;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DnsRecordsInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives domains the records of a DNS template, at their registrars.
 *
 * Adding keeps the records there and adds the template's that are missing,
 * except that a host has one record of a type such as CNAME or a forward:
 * the template's takes its place. Replacing leaves only the template's.
 * Records of a type the registrar's DNS does not take are left out. The zone
 * there before is kept, so it can be put back from the domain's DNS tab.
 */
class DnsTemplateApplier {

  // Keep the records there, and add the template's that are missing.
  public const ADD = 'add';

  // Types a host has one record of, among them all: a CNAME, or one of the
  // registrars' forwards.
  public const SINGLE_TYPES = ['CNAME', 'FORWARD', 'STEALTH', 'URL', 'URL301', 'FRAME', 'ALIAS'];

  // Leave only the template's records.
  public const REPLACE = 'replace';

  // What applying to a domain came to.
  public const CHANGED = 'changed';

  public const UNCHANGED = 'unchanged';

  // Not at a registrar whose API edits DNS, sold, or the registrar disabled.
  public const SKIPPED = 'skipped';

  // The domain's nameservers are elsewhere, so its records are too.
  public const ELSEWHERE = 'elsewhere';

  public function __construct(
    protected DnsHistory $history,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns a template's records for a domain, that its registrar takes.
   *
   * @param \Drupal\commerce_domains\Entity\DnsTemplateInterface $template
   *   The template.
   * @param string $name
   *   The domain.
   * @param array<string, mixed> $types
   *   The record types the registrar's DNS takes, as keys.
   *
   * @return array{0: \Drupal\commerce_domains\DnsRecord[], 1: string[]}
   *   The records, and the types left out.
   */
  public function records(DnsTemplateInterface $template, string $name, array $types): array {
    $records = $left_out = [];
    foreach ($template->recordsFor($name) as $record) {
      if (isset($types[$record->type])) {
        $records[] = $record;
      }
      else {
        $left_out[$record->type] = $record->type;
      }
    }
    return [$records, array_values($left_out)];
  }

  /**
   * Returns the zone a domain gets: its records with the template's.
   *
   * @param \Drupal\commerce_domains\DnsRecord[] $current
   *   The records there now.
   * @param \Drupal\commerce_domains\DnsRecord[] $template
   *   The template's records, as records() returns them.
   * @param string $mode
   *   self::ADD or self::REPLACE.
   *
   * @return \Drupal\commerce_domains\DnsRecord[]
   *   Every record the domain should have.
   */
  public static function zone(array $current, array $template, string $mode): array {
    if ($mode === self::REPLACE) {
      return $template;
    }
    // The template's CNAME or forward takes the place of the host's own.
    $single = static fn(DnsRecord $record) => in_array($record->type, self::SINGLE_TYPES, TRUE);
    $taken = [];
    foreach (array_filter($template, $single) as $record) {
      $taken[$record->host][] = $record->key();
    }
    $zone = array_values(array_filter($current, static fn(DnsRecord $record) => !$single($record) || !isset($taken[$record->host]) || in_array($record->key(), $taken[$record->host], TRUE)));
    $there = array_flip(array_map(static fn(DnsRecord $record) => $record->key(), $zone));
    foreach ($template as $record) {
      if (!isset($there[$record->key()])) {
        $zone[] = $record;
        $there[$record->key()] = TRUE;
      }
    }
    return $zone;
  }

  /**
   * Gives a domain the template's records, at its registrar, at once.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The domain's product.
   * @param \Drupal\commerce_domains\Entity\DnsTemplateInterface $template
   *   The template.
   * @param string $mode
   *   self::ADD or self::REPLACE.
   *
   * @return string
   *   One of CHANGED, UNCHANGED, SKIPPED or ELSEWHERE.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the registrar can't be read or refuses the records.
   */
  public function apply(ProductInterface $product, DnsTemplateInterface $template, string $mode): string {
    // A sold domain is the buyer's to manage now.
    $provider = Availability::isSold($product) ? NULL : DomainDnsForm::provider($product);
    if (!$provider || !$provider->status()) {
      return self::SKIPPED;
    }
    $plugin = $provider->getPlugin();
    assert($plugin instanceof DnsRecordsInterface);
    if ($error = $plugin->getSetupError()) {
      throw new ProviderException((string) $error);
    }
    $name = (string) $product->get('domain')->value;
    $current = $plugin->getDnsRecords($name);
    if ($current === NULL) {
      return self::ELSEWHERE;
    }
    [$records] = $this->records($template, $name, $plugin->dnsRecordTypes());
    $zone = self::zone($current, $records, $mode);
    $diff = DnsRecord::diff($current, $zone);
    if (!$diff['create'] && !$diff['delete'] && !$diff['ttl']) {
      return self::UNCHANGED;
    }
    $this->history->keep($name, (string) $provider->id(), $current);
    $plugin->setDnsRecords($name, $zone);
    $this->logger->notice('@domain at @provider: DNS template @template applied, @added records added and @removed removed.', [
      '@domain' => $name,
      '@provider' => $provider->label(),
      '@template' => $template->label(),
      '@added' => count($diff['create']),
      '@removed' => count($diff['delete']),
    ]);
    return self::CHANGED;
  }

}

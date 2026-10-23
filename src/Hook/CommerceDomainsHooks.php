<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Hook;

use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Commerce Domains.
 */
class CommerceDomainsHooks {

  public function __construct(
    protected DomainNameParser $parser,
    protected RegistrarSync $registrarSync,
  ) {}

  /**
   * Implements hook_entity_type_alter().
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    // Only applies to product types with the domain trait.
    $entity_types['commerce_product']->addConstraint('CommerceDomainsDomainTitle');
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->parser->refresh();
    $this->registrarSync->cron();
  }

}

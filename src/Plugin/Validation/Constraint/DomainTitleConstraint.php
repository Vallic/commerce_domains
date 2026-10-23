<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a domain product's title to be a registrable domain name.
 */
#[Constraint(
  id: 'CommerceDomainsDomainTitle',
  label: new TranslatableMarkup('Domain product title', [], ['context' => 'Validation']),
  type: ['entity:commerce_product'],
)]
class DomainTitleConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = '%title is not a domain name. Use the full name with its extension, e.g. example.com.';

}

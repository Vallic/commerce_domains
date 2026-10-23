<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Validation\Constraint;

use Drupal\commerce_domains\DomainNameParser;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the CommerceDomainsDomainTitle constraint.
 */
final class DomainTitleConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected DomainNameParser $parser) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(DomainNameParser::class));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $value */
    /** @var \Drupal\commerce_domains\Plugin\Validation\Constraint\DomainTitleConstraint $constraint */
    if (!$value->hasField('domain') || (string) $value->getTitle() === '') {
      return;
    }
    if (!$this->parser->parse($value->getTitle())) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%title', $value->getTitle())
        ->atPath('title')
        ->addViolation();
    }
  }

}

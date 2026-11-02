<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_owner\Entity\DomainRenewal;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lists the renewals paid, latest first; ?domain= for those of one domain.
 */
final class RenewalListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected DateFormatterInterface $dateFormatter,
    protected RequestStack $requestStack,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('commerce_price.currency_formatter'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    // Who gets here may see them all: the route checks the permission.
    $query = $this->getStorage()->getQuery()->accessCheck(FALSE)->sort('paid', 'DESC')->sort('renewal_id', 'DESC');
    $domain = trim((string) $this->requestStack->getCurrentRequest()?->query->get('domain'));
    if ($domain !== '') {
      $query->condition('domain', $domain);
    }
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'domain' => $this->t('Domain'),
      'paid' => $this->t('Paid on'),
      'type' => $this->t('Type'),
      'years' => $this->t('Years'),
      'amount' => $this->t('Amount'),
      'registrar' => $this->t('Registrar'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains_owner\Entity\DomainRenewalInterface $entity */
    $amount = $entity->getAmount();
    $money = $amount ? $this->currencyFormatter->format($amount->getNumber(), $amount->getCurrencyCode()) : '';
    return [
      'domain' => $entity->getDomain(),
      'paid' => $this->dateFormatter->format($entity->getPaid(), 'custom', 'Y-m-d'),
      'type' => DomainRenewal::typeLabels()[$entity->getType()] ?? $entity->getType(),
      'years' => $entity->getYears(),
      'amount' => $entity->isEstimated() ? $this->t('@amount (estimated)', ['@amount' => $money]) : $money,
      'registrar' => $entity->get('provider')->entity?->label() ?? '',
    ] + parent::buildRow($entity);
  }

}

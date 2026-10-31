<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains_lease\Entity\Lease;
use Drupal\commerce_price\Price;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists leases, the next payment due first.
 */
final class LeaseListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected DateFormatterInterface $dateFormatter,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()->accessCheck(TRUE)->sort('next_due')->sort('created', 'DESC');
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
      'buyer' => $this->t('Buyer'),
      'total' => $this->t('Total'),
      'paid' => $this->t('Paid'),
      'monthly' => $this->t('Monthly'),
      'next' => $this->t('Next payment'),
      'state' => $this->t('State'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains_lease\Entity\LeaseInterface $entity */
    return [
      'domain' => $entity->toLink($entity->getDomain()),
      'buyer' => $entity->getOrder()?->getEmail() ?? '',
      'total' => $this->money($entity->getTotal()),
      'paid' => $this->t('@paid (@made of @months monthly)', [
        '@paid' => $this->money($entity->getPaid()),
        '@made' => $entity->getPaymentsMade(),
        '@months' => $entity->getMonths(),
      ]),
      'monthly' => $this->money($entity->getInstallment()),
      'next' => $entity->getNextDue() ? $this->dateFormatter->format($entity->getNextDue(), 'short') : '',
      'state' => Lease::stateLabels()[$entity->getState()] ?? $entity->getState(),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    return [
      'view' => [
        'title' => $this->t('View'),
        'url' => $entity->toUrl(),
        'weight' => 0,
      ],
    ];
  }

  /**
   * Formats an amount.
   */
  protected function money(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}

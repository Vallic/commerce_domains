<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists offers, newest first.
 */
final class OfferListBuilder extends EntityListBuilder {

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
    $query = $this->getStorage()->getQuery()->accessCheck(TRUE)->sort('created', 'DESC');
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
      'amount' => $this->t('Offer'),
      'counter' => $this->t('Counter-offer'),
      'buyer' => $this->t('Buyer'),
      'state' => $this->t('State'),
      'created' => $this->t('Made'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains_offer\Entity\OfferInterface $entity */
    return [
      'domain' => $entity->toLink($entity->getDomain()),
      'amount' => $this->money($entity->getAmount()),
      'counter' => $this->money($entity->getCounterAmount()),
      'buyer' => $entity->getBuyerName() ?: $entity->getEmail(),
      'state' => $entity->getState()->getLabel(),
      'created' => $this->dateFormatter->format((int) $entity->get('created')->value, 'short'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    if ($entity->access('view')) {
      $operations['answer'] = [
        'title' => $this->t('Open'),
        'weight' => -10,
        'url' => $this->ensureDestination($entity->toUrl()),
      ];
    }
    return $operations;
  }

  /**
   * Formats an amount.
   */
  protected function money(?Price $price): string {
    return $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';
  }

}

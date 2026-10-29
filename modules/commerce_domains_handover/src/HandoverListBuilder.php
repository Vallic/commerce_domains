<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover;

use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the open handovers, the longest waiting first.
 *
 * Finished ones stay on their order's Handover tab.
 */
final class HandoverListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  /**
   * What the rows show besides the handovers: orders, products, registrars.
   */
  protected CacheableMetadata $rowCacheability;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
    $this->rowCacheability = new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('state', HandoverInterface::CLOSED, 'NOT IN')
      ->sort('created')
      ->sort('handover_id');
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
      'order' => $this->t('Order'),
      'buyer' => $this->t('Buyer'),
      'where' => $this->t('Where to'),
      'registrar' => $this->t('Registrar'),
      'state' => $this->t('State'),
      'started' => $this->t('Started'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains_handover\Entity\HandoverInterface $entity */
    $order = $entity->getOrder();
    $transfer = $entity->getTransfer();
    $provider = $entity->getRegistrar();
    $registrar = (string) $provider?->label();
    foreach (array_filter([$order, $entity->getProduct(), $provider]) as $dependency) {
      $this->rowCacheability->addCacheableDependency($dependency);
    }
    $domain = $entity->getDomain();
    if ($order) {
      // Straight to the steps.
      $url = Url::fromRoute('commerce_domains_handover.order', ['commerce_order' => $order->id()]);
      $access = $url->access(NULL, TRUE);
      $this->rowCacheability->addCacheableDependency($access);
      $domain = $access->isAllowed() ? Link::fromTextAndUrl($domain, $url) : $domain;
    }
    return [
      'domain' => $domain,
      'order' => $order ? $order->toLink($order->getOrderNumber() ?: $order->id()) : '',
      'buyer' => (string) $order?->getEmail(),
      'where' => $transfer ? $transfer->describe($registrar) : $this->t('Not asked'),
      'registrar' => $registrar,
      'state' => $entity->getState()->getLabel(),
      'started' => $this->dateFormatter->format($entity->getCreatedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    /** @var \Drupal\commerce_domains_handover\Entity\HandoverInterface $entity */
    $operations = [];
    if (!$entity->getOrderId()) {
      return $operations;
    }
    // The handover is worked on from its order's Handover tab.
    $url = Url::fromRoute('commerce_domains_handover.order', ['commerce_order' => $entity->getOrderId()]);
    $access = $url->access(NULL, TRUE);
    $cacheability?->addCacheableDependency($access);
    if ($access->isAllowed()) {
      $operations['open'] = [
        'title' => $this->t('Open'),
        'weight' => -10,
        'url' => $url,
      ];
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No sold domains are waiting to be handed over.');
    CacheableMetadata::createFromRenderArray($build)->merge($this->rowCacheability)->applyTo($build);
    return $build;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the renewal costs by extension, and the extensions without one.
 */
final class RenewalCostListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 100;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected DateFormatterInterface $dateFormatter,
    protected RenewalCosts $costs,
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
      $container->get(RenewalCosts::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    // Who gets here may see them all: the route checks the permission.
    $query = $this->getStorage()->getQuery()->accessCheck(FALSE)->sort('tld')->sort('provider')->sort('cost_id');
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
      'tld' => $this->t('Extension'),
      'registrar' => $this->t('Registrar'),
      'cost' => $this->t('Cost a year'),
      'changed' => $this->t('Updated'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains_owner\Entity\RenewalCost $entity */
    $cost = $entity->getCost();
    return [
      'tld' => $entity->getTld(),
      'registrar' => $entity->getProviderId() === NULL ? $this->t('Any') : ($entity->get('provider')->entity?->label() ?? $entity->getProviderId()),
      'cost' => $cost ? $this->currencyFormatter->format($cost->getNumber(), $cost->getCurrencyCode()) : '',
      'changed' => $this->dateFormatter->format($entity->getChangedTime(), 'custom', 'Y-m-d'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $missing = $this->costs->missing();
    if ($missing) {
      $build['missing'] = [
        '#markup' => '<p>' . $this->t('No cost yet for extensions of your domains: @list.', ['@list' => implode(', ', $missing)]) . '</p>',
        '#weight' => -20,
      ];
    }
    $build['#cache']['tags'][] = 'commerce_product_list';
    return $build;
  }

}

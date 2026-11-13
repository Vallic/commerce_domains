<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists DNS templates.
 */
class DnsTemplateListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Template');
    $header['records'] = $this->t('Records');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains\Entity\DnsTemplateInterface $entity */
    $row['label'] = $entity->label();
    $row['records'] = implode(', ', array_map(
      static fn(array $record) => $record['type'] . ' ' . $record['host'],
      $entity->getRecords(),
    ));
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['help'] = [
      '#markup' => '<p>' . $this->t('Records to give domains, from their DNS tab or as a bulk action on the Domains overview. Values may hold {domain}, which becomes the domain.') . '</p>',
    ];
    return $build + parent::render();
  }

}

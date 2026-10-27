<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists domain providers.
 */
class ProviderListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Provider');
    $header['plugin'] = $this->t('API');
    $header['capabilities'] = $this->t('Capabilities');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $entity */
    $plugin = $entity->getPlugin();
    // Capability interfaces and how the list names them.
    $names = [
      MarketplaceInterface::class => $this->t('Marketplace'),
      RegistrarInterface::class => $this->t('Registrar'),
      DomainManagementInterface::class => $this->t('Domain management'),
    ];
    $capabilities = array_filter($names, fn(string $interface) => $plugin instanceof $interface, ARRAY_FILTER_USE_KEY);
    $row['label'] = $entity->label();
    $row['plugin'] = $plugin ? $plugin->label() : $this->t('None');
    $row['capabilities'] = $capabilities ? implode(', ', array_map('strval', $capabilities)) : $this->t('Registrar name only');
    $row['status'] = $plugin ? ($entity->status() ? $this->t('Enabled') : $this->t('Disabled')) : '';
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    $url = $entity->toUrl('registrations');
    $access = $url->access(NULL, TRUE);
    $cacheability?->addCacheableDependency($access);
    if ($access->isAllowed()) {
      $operations['registrations'] = [
        'title' => $this->t('Registrations'),
        'weight' => 20,
        'url' => $this->ensureDestination($url),
      ];
    }
    return $operations;
  }

}

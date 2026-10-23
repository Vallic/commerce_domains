<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\EntityReferenceSelection;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderPluginManager;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Offers the providers a domain can be registered at.
 *
 * Those are providers with a registrar plugin, and providers without a plugin,
 * which stand for registrars without an API (e.g. Hover). Marketplace-only
 * providers such as Afternic are left out.
 */
#[EntityReferenceSelection(
  id: 'commerce_domains:registrar',
  label: new TranslatableMarkup('Registrars'),
  group: 'commerce_domains',
  weight: 1,
  entity_types: ['commerce_domains_provider'],
)]
final class RegistrarSelection extends DefaultSelection {

  /**
   * The provider plugin manager.
   *
   * @var \Drupal\commerce_domains\ProviderPluginManager
   */
  protected ProviderPluginManager $providerPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->providerPluginManager = $container->get('plugin.manager.commerce_domains_provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);
    $registrars = $query->orConditionGroup()->notExists('plugin');
    if ($plugin_ids = $this->providerPluginManager->getPluginIdsWith(RegistrarInterface::class)) {
      $registrars->condition('plugin', $plugin_ids, 'IN');
    }
    return $query->condition($registrars);
  }

}

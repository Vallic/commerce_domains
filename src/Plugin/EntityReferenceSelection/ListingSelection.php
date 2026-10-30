<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\EntityReferenceSelection;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderPluginManager;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Offers the providers that list domains: by API, CSV file or by hand.
 */
#[EntityReferenceSelection(
  id: 'commerce_domains_listing:provider',
  label: new TranslatableMarkup('Marketplaces'),
  // Its own group: on field creation, core picks the heaviest handler of
  // the group named before the colon.
  group: 'commerce_domains_listing',
  weight: 1,
  entity_types: ['commerce_domains_provider'],
)]
final class ListingSelection extends DefaultSelection {

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
    $plugin_ids = array_unique([
      ...$this->providerPluginManager->getPluginIdsWith(MarketplaceInterface::class),
      ...$this->providerPluginManager->getPluginIdsWith(CsvExportInterface::class),
    ]);
    // Plugins that list domains, and providers listed on by hand.
    $listing = $query->orConditionGroup()->condition('marketplace', TRUE);
    if ($plugin_ids) {
      $listing->condition('plugin', $plugin_ids, 'IN');
    }
    return $query->condition($listing);
  }

}

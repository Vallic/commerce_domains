<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\ProviderException;

/**
 * Reads a provider's sync settings, kept as its third-party settings.
 */
final class MarketplaceSettings {

  public const MODULE = 'commerce_domains_marketplace';

  /**
   * Whether catalog changes are pushed by cron without anyone running a sync.
   */
  public static function isAutoSync(ProviderInterface $provider): bool {
    return (bool) $provider->getThirdPartySetting(self::MODULE, 'auto_sync', FALSE);
  }

  /**
   * Whether listings for domains that are no longer for sale are removed.
   */
  public static function deletesUnlisted(ProviderInterface $provider): bool {
    return (bool) $provider->getThirdPartySetting(self::MODULE, 'delete_unlisted', TRUE);
  }

  /**
   * Returns the UUID of the price list whose prices this marketplace gets.
   */
  public static function priceList(ProviderInterface $provider): ?string {
    $uuid = $provider->getThirdPartySetting(self::MODULE, 'price_list');
    return is_string($uuid) && $uuid !== '' ? $uuid : NULL;
  }

  /**
   * Returns the provider's marketplace plugin.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   *   When the provider has no marketplace.
   */
  public static function plugin(ProviderInterface $provider): MarketplaceInterface {
    $plugin = $provider->getPlugin();
    if (!$plugin instanceof MarketplaceInterface) {
      throw new ProviderException(sprintf('%s has no marketplace.', $provider->label()));
    }
    return $plugin;
  }

}

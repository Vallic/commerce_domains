<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a provider plugin: the API of one registrar or marketplace company.
 *
 * What a plugin can do is given by the capability interfaces it implements,
 * e.g. \Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CommerceDomainsProvider extends Plugin {

  /**
   * Constructs a CommerceDomainsProvider attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The company's name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) What the plugin connects to and how.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}

}

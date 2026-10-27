<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\CatalogItem;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\commerce_domains\Unit\Clients\ClientTestBase;

/**
 * Base for provider plugin tests: plugins built with a mocked HTTP client.
 */
abstract class ProviderTestBase extends ClientTestBase {

  /**
   * Creates a marketplace plugin through its create() method.
   *
   * @param class-string<\Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase> $class
   *   The plugin class.
   * @param string $id
   *   The plugin ID.
   * @param array $configuration
   *   The plugin settings.
   * @param array $responses
   *   The HTTP responses the plugin's API calls get.
   */
  protected function plugin(string $class, string $id, array $configuration = [], array $responses = []): MarketplaceInterface&ProviderPluginBase {
    $plugin = $this->create($class, $id, $configuration, $responses);
    $this->assertInstanceOf(MarketplaceInterface::class, $plugin);
    return $plugin;
  }

  /**
   * Creates a registrar plugin through its create() method.
   *
   * @param class-string<\Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase> $class
   *   The plugin class.
   * @param string $id
   *   The plugin ID.
   * @param array $configuration
   *   The plugin settings.
   * @param array $responses
   *   The HTTP responses the plugin's API calls get.
   */
  protected function registrar(string $class, string $id, array $configuration = [], array $responses = []): RegistrarInterface&ProviderPluginBase {
    $plugin = $this->create($class, $id, $configuration, $responses);
    $this->assertInstanceOf(RegistrarInterface::class, $plugin);
    return $plugin;
  }

  /**
   * Creates a plugin with a mocked HTTP client.
   *
   * @param class-string<\Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase> $class
   *   The plugin class.
   * @param string $id
   *   The plugin ID.
   * @param array $configuration
   *   The plugin settings.
   * @param array $responses
   *   The HTTP responses the plugin's API calls get.
   */
  protected function create(string $class, string $id, array $configuration, array $responses): ProviderPluginBase {
    $container = new ContainerBuilder();
    $container->set('http_client', $this->http($responses));
    $container->set('uuid', new Php());
    $container->set('string_translation', $this->getStringTranslationStub());
    // Splits at the first dot; the Public Suffix List has its own test.
    $parser = $this->createMock(DomainNameParser::class);
    $parser->method('parse')->willReturnCallback(fn(string $name) => new DomainName($name, $name, ...explode('.', $name, 2)));
    $container->set(DomainNameParser::class, $parser);
    \Drupal::setContainer($container);
    $definition = ['id' => $id, 'label' => ucfirst($id), 'class' => $class, 'provider' => 'commerce_domains'];
    return $class::create($container, $configuration, $id, $definition);
  }

  /**
   * Builds a catalog item.
   */
  protected static function item(string $name, float $usd = 1000, array $overrides = []): CatalogItem {
    $values = $overrides + [
      'name' => $name,
      'title' => ucfirst($name),
      'productId' => 1,
      'forSale' => TRUE,
      'prices' => ['USD' => $usd, 'EUR' => $usd * 0.9],
      'registrar' => NULL,
      'registrarPlugin' => NULL,
      'atomPremium' => FALSE,
      'verified' => TRUE,
      'description' => NULL,
    ];
    return new CatalogItem(...$values);
  }

}

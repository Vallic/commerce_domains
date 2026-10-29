<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Clients\ApiClient;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Form\ProviderForm;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that every provider plugin the module ships takes keys.
 *
 * Each credential of each plugin comes from a key of its own: the plugin is
 * ready, its API client gets the key's value, and the value stays out of
 * the provider's config and of serialized plugins. Nothing reaches the
 * network: the HTTP client has no responses to give.
 */
#[CoversClass(ProviderPluginBase::class)]
#[CoversClass(Provider::class)]
#[CoversClass(ProviderForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ProviderPluginKeysTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['key'];

  /**
   * The environment variables the keys read, to unset afterwards.
   *
   * @var string[]
   */
  protected array $variables = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['key']);
    // The provider form links to adding a key.
    $this->container->get('router.builder')->rebuild();
    // Any request would throw: the mock has no responses.
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler())]));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->variables as $variable) {
      putenv($variable);
    }
    parent::tearDown();
  }

  /**
   * Returns the IDs of the provider plugins the module itself ships.
   *
   * @return string[]
   *   Plugin IDs.
   */
  protected function shippedPlugins(): array {
    $definitions = $this->container->get('plugin.manager.commerce_domains_provider')->getDefinitions();
    $ids = array_keys(array_filter($definitions, fn(array $definition) => str_starts_with($definition['class'], 'Drupal\\commerce_domains\\Plugin\\')));
    sort($ids);
    return $ids;
  }

  /**
   * Loads a provider as syncs do: with config overrides.
   */
  protected function load(string $id): ProviderInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_domains_provider');
    $storage->resetCache([$id]);
    $provider = $storage->load($id);
    $this->assertInstanceOf(ProviderInterface::class, $provider);
    return $provider;
  }

  /**
   * Returns every string an object holds, a few levels deep.
   *
   * HTTP clients are left out: they hold no credentials of their own.
   *
   * @return string[]
   *   The strings.
   */
  protected static function strings(mixed $value, int $depth = 0): array {
    if (is_string($value)) {
      return [$value];
    }
    if ($depth > 3 || $value instanceof ClientInterface || $value instanceof \Closure) {
      return [];
    }
    $strings = [];
    if (is_array($value)) {
      foreach ($value as $item) {
        array_push($strings, ...self::strings($item, $depth + 1));
      }
    }
    elseif (is_object($value)) {
      foreach ((new \ReflectionObject($value))->getProperties() as $property) {
        if (!$property->isStatic() && $property->isInitialized($value)) {
          array_push($strings, ...self::strings($property->getValue($value), $depth + 1));
        }
      }
    }
    return $strings;
  }

  /**
   * Returns the API clients a plugin builds, without calling the API.
   *
   * @return \Drupal\commerce_domains\Clients\ApiClient[]
   *   The clients.
   */
  protected static function clients(object $plugin): array {
    $clients = [];
    foreach (['client', 'registrarClient'] as $method) {
      if (method_exists($plugin, $method)) {
        $client = (new \ReflectionMethod($plugin, $method))->invoke($plugin);
        self::assertInstanceOf(ApiClient::class, $client);
        $clients[] = $client;
      }
    }
    return $clients;
  }

  /**
   * Tests each plugin with every credential from a key.
   */
  public function testKeys(): void {
    $plugins = $this->shippedPlugins();
    $this->assertContains('afternic', $plugins);
    $with_credentials = 0;
    foreach ($plugins as $plugin_id) {
      $provider = Provider::create([
        'id' => $plugin_id,
        'label' => "Test $plugin_id",
        'plugin' => $plugin_id,
        'status' => TRUE,
      ]);
      $sources = $provider->getPlugin()->getCredentialSources();

      // A key per credential, each reading a variable of its own.
      $keys = $secrets = [];
      foreach ($sources as $setting => $source) {
        $variable = 'COMMERCE_DOMAINS_TEST_' . strtoupper($plugin_id . '_' . $setting);
        $secret = 'Key/' . $plugin_id . '+' . $setting . ' ' . $this->randomMachineName();
        putenv("$variable=$secret");
        $this->variables[] = $variable;
        $key_id = $plugin_id . '_' . $setting;
        Key::create([
          'id' => $key_id,
          'label' => "$plugin_id $setting",
          'key_type' => 'authentication',
          'key_provider' => 'env',
          'key_provider_settings' => [
            'env_variable' => $variable,
            'base64_encoded' => FALSE,
            'strip_line_breaks' => TRUE,
          ],
          'key_input' => 'none',
        ])->save();
        $keys[$setting] = $key_id;
        $secrets[$setting] = $secret;
      }
      $provider->setCredentialKeys($keys)->save();
      $with_credentials += (int) (bool) $keys;

      $provider = $this->load($plugin_id);
      $plugin = $provider->getPlugin();
      $this->assertNull($plugin->getSetupError(), "$plugin_id is ready with keys.");
      foreach ($plugin->getCredentialSources() as $setting => $source) {
        $this->assertSame('key', $source['source'], "$plugin_id $setting comes from its key.");
      }
      foreach ($keys as $key_id) {
        $this->assertContains("key.key.$key_id", $provider->getDependencies()['config'] ?? [], "$plugin_id depends on $key_id.");
      }

      // The clients get the keys' values.
      $held = [];
      foreach (self::clients($plugin) as $client) {
        array_push($held, ...self::strings($client));
      }
      foreach ($secrets as $setting => $secret) {
        $this->assertContains($secret, $held, "$plugin_id's client has $setting.");
      }

      // The values stay out of config and of serialized plugins.
      $exported = json_encode($this->config("commerce_domains.provider.$plugin_id")->getRawData());
      $serialized = serialize($plugin);
      $configuration = json_encode($plugin->getConfiguration());
      foreach ($secrets as $setting => $secret) {
        $this->assertStringNotContainsString($secret, $exported, "$plugin_id $setting is not in config.");
        $this->assertStringNotContainsString($secret, $serialized, "$plugin_id $setting is not in the serialized plugin.");
        $this->assertStringNotContainsString($secret, $configuration, "$plugin_id $setting is not in the plugin's configuration.");
      }
      // Saving the provider again keeps them out too.
      $provider->save();
      $exported = json_encode($this->config("commerce_domains.provider.$plugin_id")->getRawData());
      foreach ($secrets as $secret) {
        $this->assertStringNotContainsString($secret, $exported);
      }
    }
    $this->assertGreaterThan(10, $with_credentials, 'Most plugins have credentials.');
  }

  /**
   * Tests that each plugin's settings form offers a key for each credential.
   */
  public function testForms(): void {
    $this->setUpCurrentUser([], ['administer commerce_domains providers', 'administer keys']);
    $storage = $this->entityTypeManager->getStorage('commerce_domains_provider');
    $this->assertInstanceOf(ConfigEntityStorageInterface::class, $storage);
    foreach ($this->shippedPlugins() as $plugin_id) {
      Provider::create(['id' => $plugin_id, 'label' => "Test $plugin_id", 'plugin' => $plugin_id])->save();
      $sources = $this->load($plugin_id)->getPlugin()->getCredentialSources();

      $form_object = $this->entityTypeManager->getFormObject('commerce_domains_provider', 'edit');
      $form_object->setEntity($storage->loadOverrideFree($plugin_id));
      $form = $this->container->get('form_builder')->getForm($form_object);
      if (!$sources) {
        $this->assertArrayNotHasKey('credential_keys', $form['configuration'] ?? [], "$plugin_id has no credentials.");
        continue;
      }
      $element = $form['configuration']['credential_keys'];
      $this->assertSame('fieldset', $element['#type']);
      foreach ($sources as $setting => $source) {
        $this->assertSame('key_select', $element[$setting]['#type'], "$plugin_id $setting");
        // The schema names each credential, besides its variable.
        $this->assertMatchesRegularExpression('/^.+ \(' . preg_quote($source['variable'], '/') . '\)$/', (string) $element[$setting]['#title'], "$plugin_id $setting has a label.");
      }
    }
  }

}

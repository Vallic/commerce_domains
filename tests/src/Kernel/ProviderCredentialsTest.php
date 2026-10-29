<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Form\ProviderForm;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_credentials_test\Plugin\CommerceDomains\Provider\TestCredentials;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests where API credentials come from: settings.php, or keys (Key module).
 */
#[CoversClass(ProviderPluginBase::class)]
#[CoversClass(Provider::class)]
#[CoversClass(ProviderForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ProviderCredentialsTest extends DomainsKernelTestBase {

  // The values the test keys read, as a host's control panel would set them.
  protected const ENV_KEY = 'Env-key-value-1';
  protected const FILE_SECRET = 'File/secret+value 2';
  protected const CONFIG_SECRET = 'Config-kept-value-3';
  protected const SETTINGS_KEY = 'Settings-php-value-4';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_credentials_test'];

  /**
   * The requests the API got.
   *
   * @var array[]
   */
  protected array $requests = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('COMMERCE_DOMAINS_TEST_ENV_KEY=' . self::ENV_KEY);
    putenv('COMMERCE_DOMAINS_TEST_EMPTY_KEY');
    file_put_contents($this->siteDirectory . '/api-secret.key', self::FILE_SECRET . "\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('COMMERCE_DOMAINS_TEST_ENV_KEY');
    unset($GLOBALS['config']['commerce_domains.provider.example']);
    parent::tearDown();
  }

  /**
   * Installs the Key module, with keys for each kind of provider.
   */
  protected function installKey(): void {
    $this->container->get('module_installer')->install(['key']);
    // The key form link needs the routes.
    $this->container->get('router.builder')->rebuild();
    $read = ['base64_encoded' => FALSE, 'strip_line_breaks' => TRUE];
    $keys = [
      'env_key' => ['env', ['env_variable' => 'COMMERCE_DOMAINS_TEST_ENV_KEY'] + $read],
      'empty_key' => ['env', ['env_variable' => 'COMMERCE_DOMAINS_TEST_EMPTY_KEY'] + $read],
      'file_secret' => ['file', ['file_location' => $this->siteDirectory . '/api-secret.key'] + $read],
      'config_secret' => ['config', ['key_value' => self::CONFIG_SECRET, 'base64_encoded' => FALSE]],
    ];
    foreach ($keys as $id => [$provider, $settings]) {
      Key::create([
        'id' => $id,
        'label' => ucfirst(str_replace('_', ' ', $id)),
        'key_type' => 'authentication',
        'key_provider' => $provider,
        'key_provider_settings' => $settings,
        'key_input' => 'none',
      ])->save();
    }
  }

  /**
   * Puts a mocked API in the container, recording what it is sent.
   */
  protected function mockApi(): void {
    $this->requests = [];
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 5, new Response(200, [], '{}'))));
    $stack->push(Middleware::history($this->requests));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Creates the test provider, with keys for some credentials.
   *
   * @param array<string, string> $keys
   *   Key IDs, keyed by setting.
   */
  protected function createProvider(array $keys = []): ProviderInterface {
    $provider = Provider::create(['id' => 'example', 'label' => 'Example', 'plugin' => 'test_credentials']);
    $provider->setCredentialKeys($keys)->save();
    return $this->load();
  }

  /**
   * Loads the test provider as syncs do: with config overrides.
   */
  protected function load(): ProviderInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_domains_provider');
    $storage->resetCache();
    $provider = $storage->load('example');
    $this->assertInstanceOf(ProviderInterface::class, $provider);
    return $provider;
  }

  /**
   * Returns the test provider's plugin.
   */
  protected function plugin(ProviderInterface $provider): TestCredentials {
    $plugin = $provider->getPlugin();
    $this->assertInstanceOf(TestCredentials::class, $plugin);
    return $plugin;
  }

  /**
   * Sets a credential in settings.php, as $config overrides do.
   */
  protected function setInSettings(string $setting, mixed $value): void {
    $GLOBALS['config']['commerce_domains.provider.example']['configuration'][$setting] = $value;
    $this->container->get('config.factory')->reset('commerce_domains.provider.example');
  }

  /**
   * Returns a header of a request the API got.
   */
  protected function header(int $request, string $name): string {
    return $this->requests[$request]['request']->getHeaderLine($name);
  }

  /**
   * Builds the provider's edit form, as the admin page does.
   */
  protected function buildForm(): array {
    return $this->container->get('form_builder')->getForm($this->formObject());
  }

  /**
   * Returns the edit form for the provider, loaded as admin pages load it.
   */
  protected function formObject(): ProviderForm {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $storage = $entity_type_manager->getStorage('commerce_domains_provider');
    $this->assertInstanceOf(ConfigEntityStorageInterface::class, $storage);
    $storage->resetCache();
    $form_object = $entity_type_manager->getFormObject('commerce_domains_provider', 'edit');
    $this->assertInstanceOf(ProviderForm::class, $form_object);
    $form_object->setEntity($storage->loadOverrideFree('example'));
    return $form_object;
  }

  /**
   * Submits the provider's edit form.
   */
  protected function submitForm(array $values): FormStateInterface {
    $form_state = (new FormState())->setValues($values + ['label' => 'Example', 'status' => 1, 'op' => 'Save']);
    $this->container->get('form_builder')->submitForm($this->formObject(), $form_state);
    return $form_state;
  }

  /**
   * Submits the provider's edit form with a key choice for each credential.
   *
   * @param array<string, string> $keys
   *   Key IDs, keyed by setting; the other credentials get none.
   */
  protected function submitKeys(array $keys): FormStateInterface {
    return $this->submitForm(['credential_keys' => $keys + ['api_key' => '', 'api_secret' => '', 'extra_token' => '']]);
  }

  /**
   * Asserts that no test secret appears in a string.
   */
  protected function assertNoSecret(string $haystack, string $message = ''): void {
    foreach ([self::ENV_KEY, self::FILE_SECRET, self::CONFIG_SECRET, self::SETTINGS_KEY] as $secret) {
      $this->assertStringNotContainsString($secret, $haystack, $message);
    }
  }

  /**
   * Tests that a chosen key's value reaches the API, and never config.
   */
  public function testKeyIsRead(): void {
    $this->installKey();
    $this->mockApi();
    $provider = $this->createProvider(['api_key' => 'env_key', 'api_secret' => 'file_secret']);

    // The provider depends on its keys, and stores only their IDs.
    $config = $this->config('commerce_domains.provider.example');
    $this->assertSame(['api_key' => 'env_key', 'api_secret' => 'file_secret'], $config->get('credential_keys'));
    $this->assertSame(['key.key.env_key', 'key.key.file_secret'], $config->get('dependencies.config'));
    $this->assertSame('', $config->get('configuration.api_key'));

    $plugin = $this->plugin($provider);
    $this->assertNull($plugin->getSetupError());
    $sources = $plugin->getCredentialSources();
    $this->assertSame('key', $sources['api_key']['source']);
    $this->assertSame('env_key', $sources['api_key']['key']);
    $this->assertSame('Env key', $sources['api_key']['key_label']);
    $this->assertSame('key', $sources['api_secret']['source']);
    $this->assertNull($sources['extra_token']['source']);
    $this->assertNoSecret(serialize($sources));

    $plugin->ping();
    $this->assertSame(self::ENV_KEY, $this->header(0, 'X-Api-Key'));
    $this->assertSame(self::FILE_SECRET, $this->header(0, 'X-Api-Secret'), 'The file key lost its trailing line break.');

    // Saving or serializing the provider after the call keeps the values out.
    $this->assertSame('', $plugin->getConfiguration()['api_key']);
    $provider->save();
    $this->assertNoSecret(serialize($this->config('commerce_domains.provider.example')->getRawData()));
    $this->assertNoSecret(serialize($provider));
    $serialized = serialize($plugin);
    $this->assertNoSecret($serialized, 'Neither the settings nor the API client are serialized with values.');
    // Both copies still call the API: the unserialized one reads its keys
    // and builds its client again.
    $this->assertTrue($plugin->hasClient());
    $plugin->ping();
    // Only the plugin and its label: no API client, nor anything holding one.
    $copy = unserialize($serialized, ['allowed_classes' => [TestCredentials::class, TranslatableMarkup::class]]);
    $this->assertInstanceOf(TestCredentials::class, $copy);
    $this->assertFalse($copy->hasClient());
    $copy->ping();
    $this->assertSame(self::ENV_KEY, $this->header(1, 'X-Api-Key'));
    $this->assertSame(self::FILE_SECRET, $this->header(2, 'X-Api-Secret'));

    // An optional credential without a key fails only the calls needing it.
    try {
      $plugin->pingExtra();
      $this->fail('The extra call needs the token.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertSame('Test credentials needs another credential for this: set COMMERCE_DOMAINS_TEST_EXTRA_TOKEN.', $e->getMessage());
    }
    $provider->setCredentialKeys(['extra_token' => 'env_key'] + $provider->getCredentialKeys())->save();
    $this->plugin($this->load())->pingExtra();
    $this->assertSame('Bearer ' . self::ENV_KEY, $this->header(3, 'Authorization'));
  }

  /**
   * Tests the keys on the provider form.
   */
  public function testForm(): void {
    $this->installKey();
    $this->createProvider();

    $form = $this->buildForm();
    $keys = $form['configuration']['credential_keys'];
    $this->assertSame('key_select', $keys['api_key']['#type']);
    $this->assertSame(['credential_keys', 'api_key'], $keys['api_key']['#parents']);
    $this->assertSame('API key (COMMERCE_DOMAINS_TEST_API_KEY)', (string) $keys['api_key']['#title']);
    $this->assertArrayHasKey('env_key', $keys['api_key']['#options']);
    $this->assertSame('Missing: set it in settings.php, or choose a key.', (string) $keys['api_key']['#description']);
    $this->assertSame('Optional; not set.', (string) $keys['extra_token']['#description']);
    // Right after the help naming the variables.
    $children = array_values(array_filter(array_keys($form['configuration']), fn($key) => !str_starts_with((string) $key, '#')));
    $this->assertSame(['credentials', 'credential_keys'], $children);

    $form_state = $this->submitKeys(['api_key' => 'env_key', 'api_secret' => 'file_secret']);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(['api_key' => 'env_key', 'api_secret' => 'file_secret'], $this->load()->getCredentialKeys());

    $keys = $this->buildForm()['configuration']['credential_keys'];
    $this->assertSame('env_key', $keys['api_key']['#default_value']);
    $this->assertSame('In use: key <em class="placeholder">Env key</em>.', (string) $keys['api_key']['#description']);
    $this->assertSame('In use: key <em class="placeholder">File secret</em>.', (string) $keys['api_secret']['#description']);

    // Choosing no key takes it off the provider.
    $this->submitKeys(['api_key' => 'env_key']);
    $this->assertSame(['api_key' => 'env_key'], $this->load()->getCredentialKeys());
  }

  /**
   * Tests that keys keeping their value in config are refused.
   */
  public function testConfigKeyRefused(): void {
    $this->installKey();
    $this->mockApi();
    $this->assertTrue(ProviderPluginBase::keyStoresValueInConfig(Key::load('config_secret')));
    $this->assertFalse(ProviderPluginBase::keyStoresValueInConfig(Key::load('env_key')));
    $this->assertFalse(ProviderPluginBase::keyStoresValueInConfig(Key::load('file_secret')));

    // The form refuses one.
    $this->createProvider();
    $form_state = $this->submitKeys(['api_key' => 'config_secret', 'api_secret' => 'file_secret']);
    $errors = $form_state->getErrors();
    $this->assertSame(['credential_keys][api_key'], array_keys($errors));
    $this->assertStringContainsString('keeps its value in configuration, which is exported with the site', (string) $errors['credential_keys][api_key']);
    $this->assertSame([], $this->load()->getCredentialKeys());

    // One chosen anyway, e.g. switched to the Configuration provider later,
    // is never read.
    $provider = $this->load()->setCredentialKeys(['api_key' => 'config_secret', 'api_secret' => 'file_secret']);
    $provider->save();
    $plugin = $this->plugin($this->load());
    $this->assertSame('key_config', $plugin->getCredentialSources()['api_key']['problem']);
    $error = $plugin->getSetupError();
    $this->assertSame('Test credentials API credentials are missing: key "Config secret" for COMMERCE_DOMAINS_TEST_API_KEY keeps its value in config, which is exported with the site: use a key that reads an environment variable or a file.', $error);
    try {
      $plugin->ping();
      $this->fail('A refused key leaves the credential missing.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertNoSecret($e->getMessage());
    }
    $this->assertSame([], $this->requests);
    $this->assertStringContainsString('keeps its value in configuration', (string) $this->buildForm()['configuration']['credential_keys']['api_key']['#description']);
  }

  /**
   * Tests that a settings.php override, e.g. from getenv(), wins over a key.
   */
  public function testEnvironmentOverrideWins(): void {
    $this->installKey();
    $this->mockApi();
    $this->createProvider(['api_key' => 'env_key', 'api_secret' => 'file_secret']);
    $this->setInSettings('api_key', self::SETTINGS_KEY);

    $plugin = $this->plugin($this->load());
    $this->assertSame('settings', $plugin->getCredentialSources()['api_key']['source']);
    $plugin->ping();
    $this->assertSame(self::SETTINGS_KEY, $this->header(0, 'X-Api-Key'));
    $this->assertSame(self::FILE_SECRET, $this->header(0, 'X-Api-Secret'));

    // The form, which loads the provider without overrides, says so.
    $keys = $this->buildForm()['configuration']['credential_keys'];
    $this->assertSame('In use: the value from settings.php, which wins over the key.', (string) $keys['api_key']['#description']);
    $this->assertSame('In use: key <em class="placeholder">File secret</em>.', (string) $keys['api_secret']['#description']);
    // Saving it writes neither the settings.php value nor the key's.
    $this->assertSame([], $this->submitKeys(['api_key' => 'env_key', 'api_secret' => 'file_secret'])->getErrors());
    $this->assertNoSecret(serialize($this->container->get('config.storage')->read('commerce_domains.provider.example')));

    // An empty value from settings.php, as getenv() gives for an unset
    // variable, leaves the key in use.
    $this->setInSettings('api_key', FALSE);
    $this->plugin($this->load())->ping();
    $this->assertSame(self::ENV_KEY, $this->header(1, 'X-Api-Key'));

    // A value saved in config, against the advice, is used but flagged.
    unset($GLOBALS['config']['commerce_domains.provider.example']);
    $this->container->get('config.factory')->reset('commerce_domains.provider.example');
    $this->container->get('config.factory')->getEditable('commerce_domains.provider.example')->set('configuration.api_key', self::SETTINGS_KEY)->save();
    $keys = $this->buildForm()['configuration']['credential_keys'];
    $this->assertStringStartsWith("In use: a value saved in the provider's configuration", (string) $keys['api_key']['#description']);
  }

  /**
   * Tests that missing keys are reported without any credential's value.
   */
  public function testMissingKeyReported(): void {
    $this->installKey();
    $this->mockApi();
    $provider = $this->createProvider(['api_key' => 'env_key', 'api_secret' => 'gone']);
    $error = $this->plugin($provider)->getSetupError();
    $this->assertSame('Test credentials API credentials are missing: key "gone" for COMMERCE_DOMAINS_TEST_API_SECRET does not exist.', $error);
    $this->assertSame(['key.key.env_key'], $this->config('commerce_domains.provider.example')->get('dependencies.config'));

    $provider->setCredentialKeys(['api_key' => 'env_key', 'api_secret' => 'empty_key'])->save();
    $plugin = $this->plugin($this->load());
    $this->assertSame('Test credentials API credentials are missing: key "Empty key" for COMMERCE_DOMAINS_TEST_API_SECRET is empty.', $plugin->getSetupError());
    try {
      $plugin->ping();
      $this->fail('The call needs both credentials.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable, 'Queued work waits for the key.');
      $this->assertNoSecret($e->getMessage());
    }
    $this->assertSame([], $this->requests);
    $this->assertSame('Not in use: key <em class="placeholder">Empty key</em> is empty.', (string) $this->buildForm()['configuration']['credential_keys']['api_secret']['#description']);

    // Deleting a key takes it off the provider instead of deleting the
    // provider; the credential then has to come from settings.php.
    $this->load()->setCredentialKeys(['api_key' => 'env_key', 'api_secret' => 'file_secret'])->save();
    Key::load('file_secret')->delete();
    $provider = $this->load();
    $this->assertSame(['api_key' => 'env_key'], $provider->getCredentialKeys());
    $this->assertSame('Test credentials API credentials are missing: set COMMERCE_DOMAINS_TEST_API_SECRET.', $this->plugin($provider)->getSetupError());

    // So does uninstalling the Key module.
    $this->installSchema('user', ['users_data']);
    $this->container->get('module_installer')->uninstall(['key']);
    $provider = $this->load();
    $this->assertSame([], $provider->getCredentialKeys());
    $this->assertSame('Test credentials API credentials are missing: set COMMERCE_DOMAINS_TEST_API_KEY and COMMERCE_DOMAINS_TEST_API_SECRET.', $this->plugin($provider)->getSetupError());
  }

  /**
   * Tests the update that gives existing providers their keys setting.
   */
  public function testUpdate(): void {
    $this->createProvider();
    $config = $this->container->get('config.factory')->getEditable('commerce_domains.provider.example');
    $data = $config->getRawData();
    unset($data['credential_keys']);
    $config->setData($data)->save();

    $this->container->get('module_handler')->loadInclude('commerce_domains', 'install');
    commerce_domains_update_11002();
    $data = $this->config('commerce_domains.provider.example')->getRawData();
    $this->assertSame([], $data['credential_keys']);
    // Where saving the provider puts it, so the next export doesn't move it.
    $names = array_keys($data);
    $this->assertSame('credential_keys', $names[array_search('configuration', $names, TRUE) + 1]);
    $this->load()->save();
    $this->assertSame($names, array_keys($this->config('commerce_domains.provider.example')->getRawData()));
  }

  /**
   * Tests credentials without the Key module: settings.php only, as before.
   */
  public function testWithoutKeyModule(): void {
    $this->mockApi();
    $provider = $this->createProvider();
    $plugin = $this->plugin($provider);
    $this->assertSame('Test credentials API credentials are missing: set COMMERCE_DOMAINS_TEST_API_KEY and COMMERCE_DOMAINS_TEST_API_SECRET.', $plugin->getSetupError());

    $this->setInSettings('api_key', self::SETTINGS_KEY);
    $plugin = $this->plugin($this->load());
    $this->assertSame('Test credentials API credentials are missing: set COMMERCE_DOMAINS_TEST_API_SECRET.', $plugin->getSetupError());
    $this->setInSettings('api_secret', 'Settings-secret');
    $plugin = $this->plugin($this->load());
    $this->assertNull($plugin->getSetupError());
    $plugin->ping();
    $this->assertSame(self::SETTINGS_KEY, $this->header(0, 'X-Api-Key'));
    $this->assertSame('settings', $plugin->getCredentialSources()['api_key']['source']);

    // The form only says where each credential comes from.
    $keys = $this->buildForm()['configuration']['credential_keys'];
    $this->assertSame('item', $keys['api_key']['#type']);
    $this->assertSame('In use: the value from settings.php.', (string) $keys['api_key']['#description']);
    $this->assertSame('Optional; not set.', (string) $keys['extra_token']['#description']);
    $form_state = $this->submitForm([]);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([], $this->load()->getCredentialKeys());
    $this->assertNoSecret(serialize($this->container->get('config.storage')->read('commerce_domains.provider.example')));

    // Keys named in config can't be read without the module.
    unset($GLOBALS['config']['commerce_domains.provider.example']);
    $this->container->get('config.factory')->reset('commerce_domains.provider.example');
    $this->load()->setCredentialKeys(['api_key' => 'env_key'])->save();
    $this->assertSame([], $this->config('commerce_domains.provider.example')->get('dependencies.config') ?? []);
    $this->assertSame('Test credentials API credentials are missing: set COMMERCE_DOMAINS_TEST_API_SECRET; key "env_key" for COMMERCE_DOMAINS_TEST_API_KEY needs the Key module.', $this->plugin($this->load())->getSetupError());
  }

}

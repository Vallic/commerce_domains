<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\Drush\Commands\MarketplaceCommands;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Log\DrushLoggerManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use League\Container\Container;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the marketplace sync Drush command.
 */
#[CoversClass(MarketplaceCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceCommandsTest extends MarketplaceKernelTestBase {

  /**
   * The command's output.
   *
   * @var \Symfony\Component\Console\Output\BufferedOutput
   */
  protected BufferedOutput $output;

  /**
   * What the command logged, as [level, message] pairs.
   *
   * @var array
   */
  protected array $logged = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!class_exists(DrushCommands::class)) {
      $this->markTestSkipped('Drush is not installed.');
    }
    // dt() lives in an include that only Drush's own bootstrap loads.
    require_once dirname((new \ReflectionClass(DrushCommands::class))->getFileName(), 3) . '/includes/output.inc';
    $this->createDomain('Same.com', 1000);
    $this->createDomain('Repriced.com', 2000);
    $this->createDomain('New.com', 3000);
    $this->setListings(['same.com' => ['price' => 1000], 'repriced.com' => ['price' => 1]]);
  }

  /**
   * Runs the command as `drush cdms PROVIDER --yes` would.
   */
  protected function runCommand(string $provider, array $options = []): int {
    $command = new MarketplaceCommands($this->entityTypeManager, $this->container->get(MarketplaceSync::class));
    $input = new ArrayInput(['--yes' => TRUE], new InputDefinition([new InputOption('yes', 'y')]));
    $input->setInteractive(FALSE);
    // Confirmations read --yes from Drush's own container.
    $drush = new Container();
    $drush->add('input', $input);
    Drush::setContainer($drush);
    $command->setInput($input);
    $this->output = new BufferedOutput();
    $command->setOutput($this->output);

    $this->logged = [];
    $logger = new class($this->logged) extends AbstractLogger {

      public function __construct(protected array &$logged) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->logged[] = [$level, (string) $message];
      }

    };
    $manager = new DrushLoggerManager();
    $manager->add('test', $logger);
    $command->setLogger($manager);

    return $command->sync($provider, $options + ['domains' => NULL, 'dry-run' => FALSE, 'details' => FALSE]);
  }

  /**
   * Returns the logged messages of one level.
   */
  protected function messages(string $level): array {
    return array_column(array_filter($this->logged, fn(array $entry) => $entry[0] === $level), 1);
  }

  /**
   * Tests refusing providers it can't sync.
   */
  public function testUnknownProviders(): void {
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('missing'));
    $this->assertSame(['No provider missing with a marketplace.'], $this->messages('error'));

    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('gname'));
  }

  /**
   * Tests a dry run with details, which works while disabled.
   */
  public function testDryRun(): void {
    $this->createProvider('test', FALSE);
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('test', ['dry-run' => TRUE, 'details' => TRUE]));
    $output = $this->output->fetch();
    $this->assertStringContainsString('3 catalog domains to list on Test; 2 listed there now.', str_replace("\n", ' ', $output));
    $this->assertMatchesRegularExpression('/Update\s+1\s+price ×1/', $output);
    $this->assertMatchesRegularExpression('/new\.com\s+add\s+price 3000/', $output);
    $this->assertMatchesRegularExpression('/repriced\.com\s+update\s+price 1 → 2000/', $output);
    $this->assertSame(1, $this->listings()['repriced.com']['price'], 'A dry run changes nothing.');
  }

  /**
   * Tests syncing chosen domains, typed as people type them.
   */
  public function testDomainsOption(): void {
    $this->createProvider('test');
    $this->runCommand('test', ['domains' => 'Repriced.COM, new.com', 'dry-run' => TRUE]);
    $this->assertSame([['fetch', ['repriced.com', 'new.com']]], $this->calls());
  }

  /**
   * Tests pushing, and refusing to push to a disabled provider.
   */
  public function testPush(): void {
    $this->createProvider('off', FALSE);
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('off'));
    $this->assertSame(['Off is disabled; enable it before pushing.'], $this->messages('error'));

    $this->createProvider('test');
    $this->container->get('state')->set('commerce_domains_test.reject', ['new.com']);
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('test'));
    $this->assertSame(['new.com: Rejected by the test marketplace.'], $this->messages('warning'));
    $this->assertSame(2000, $this->listings()['repriced.com']['price']);

    $this->container->get('state')->delete('commerce_domains_test.reject');
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('test'));
    $this->assertStringContainsString('[OK] Test is in sync.', $this->output->fetch());
    $this->assertSame(3000, $this->listings()['new.com']['price']);
  }

}

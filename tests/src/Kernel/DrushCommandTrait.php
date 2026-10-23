<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Log\DrushLoggerManager;
use League\Container\Container;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs Drush commands in kernel tests, as `drush COMMAND --yes` would.
 */
trait DrushCommandTrait {

  /**
   * The latest command's output.
   *
   * @var \Symfony\Component\Console\Output\BufferedOutput
   */
  protected BufferedOutput $output;

  /**
   * What the latest command logged, as [level, message] pairs.
   *
   * @var array
   */
  protected array $logged = [];

  /**
   * Skips the test without Drush, and loads what its commands need.
   */
  protected function requireDrush(): void {
    if (!class_exists(DrushCommands::class)) {
      $this->markTestSkipped('Drush is not installed.');
    }
    // dt() lives in an include that only Drush's own bootstrap loads.
    require_once dirname((new \ReflectionClass(DrushCommands::class))->getFileName(), 3) . '/includes/output.inc';
  }

  /**
   * Prepares a command: --yes input, buffered output, a recording logger.
   */
  protected function prepareCommand(DrushCommands $command): void {
    $input = new ArrayInput(['--yes' => TRUE], new InputDefinition([new InputOption('yes', 'y')]));
    $input->setInteractive(FALSE);
    $command->setInput($input);
    // Confirmations read --yes from Drush's own container.
    $drush = new Container();
    $drush->add('input', $input);
    Drush::setContainer($drush);

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
  }

  /**
   * Returns the logged messages of one level.
   */
  protected function messages(string $level): array {
    return array_column(array_filter($this->logged, fn(array $entry) => $entry[0] === $level), 1);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Drush\Commands\RegistrarCommands;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\RegistrarSync;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the registrar sync Drush command.
 */
#[CoversClass(RegistrarCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RegistrarCommandsTest extends DomainsKernelTestBase {

  use DrushCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->requireDrush();
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar', 'status' => FALSE])->save();
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
    $this->createDomain('Renewed.com', 1000, ['domain_registrar' => 'reg', 'domain_expires' => 1_820_000_000]);
    $this->createDomain('Gone.com', 1000, ['domain_registrar' => 'reg']);
    $this->createDomain('Moved.com', 1000, ['domain_registrar' => 'gname']);
    $this->container->get('state')->set('commerce_domains_test.registrations', [
      'renewed.com' => 1_830_000_000,
      'moved.com' => 1_830_000_000,
    ]);
  }

  /**
   * Runs the command as `drush cdrs PROVIDER --yes` would.
   */
  protected function runCommand(string $provider, array $options = []): int {
    $command = new RegistrarCommands($this->entityTypeManager, $this->container->get(RegistrarSync::class));
    $this->prepareCommand($command);
    $defaults = ['domains' => NULL, 'dry-run' => FALSE, 'details' => FALSE, 'fix-registrar' => FALSE];
    return $command->sync($provider, $options + $defaults);
  }

  /**
   * Returns the stored expiry of a domain.
   */
  protected function expires(string $domain): ?int {
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => $domain]);
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = reset($products);
    $value = $product->get('domain_expires')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * Tests refusing providers without a registrar API.
   */
  public function testUnknownProviders(): void {
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('gname'));
    $this->assertSame(['No provider gname with a registrar API.'], $this->messages('error'));
  }

  /**
   * Tests a dry run with details, which works while disabled.
   */
  public function testDryRun(): void {
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('reg', ['dry-run' => TRUE, 'details' => TRUE]));
    $output = $this->output->fetch();
    $this->assertStringContainsString('2 domains in the Reg account.', $output);
    $this->assertMatchesRegularExpression('/Expiry to update\s+1/', $output);
    $this->assertMatchesRegularExpression('/Not in the account\s+1\s+gone\.com/', $output);
    $this->assertMatchesRegularExpression('/renewed\.com\s+expiry\s+2027-09-03 → 2027-12-28/', $output);
    $this->assertMatchesRegularExpression('/moved\.com\s+recorded at gname/', $output);
    $this->assertSame(1_820_000_000, $this->expires('renewed.com'), 'A dry run changes nothing.');
  }

  /**
   * Tests updating, and refusing to update from a disabled provider.
   */
  public function testSync(): void {
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('reg'));
    $this->assertSame(['Reg is disabled; enable it before syncing.'], $this->messages('error'));

    Provider::load('reg')->enable()->save();
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('reg', ['fix-registrar' => TRUE]));
    $this->assertStringContainsString('[OK] Updated 2 products from Reg.', $this->output->fetch());
    $this->assertSame(1_830_000_000, $this->expires('renewed.com'));
    $this->assertSame(1_830_000_000, $this->expires('moved.com'));

    // Nothing left to do: no confirmation, no update.
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('reg', ['fix-registrar' => TRUE]));
    $this->assertStringNotContainsString('Updated', $this->output->fetch());
  }

}

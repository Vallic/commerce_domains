<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\Drush\Commands\ImportCommands;
use Drupal\commerce_price\Price;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the import Drush command.
 */
#[CoversClass(ImportCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ImportCommandsTest extends ImportKernelTestBase {

  use DrushCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->requireDrush();
  }

  /**
   * Runs the command as `drush cdi FILE` would.
   */
  protected function runCommand(?string $file, array $options = []): int {
    $command = new ImportCommands($this->entityTypeManager, $this->container->get(Catalog::class), $this->container->get(DomainImporter::class));
    $this->prepareCommand($command);
    $defaults = [
      'format' => NULL,
      'from-registrar' => NULL,
      'type' => NULL,
      'store' => NULL,
      'price' => NULL,
      'currency' => NULL,
      'buy-now' => TRUE,
      'offers' => TRUE,
      'unpublished' => FALSE,
      'update' => FALSE,
      'lookup-registrars' => FALSE,
      'dry-run' => FALSE,
      'details' => FALSE,
    ];
    return $command->import($file, $options + $defaults);
  }

  /**
   * Writes a file to import, and returns its path.
   */
  protected function file(string $name, string $content): string {
    $path = $this->siteDirectory . '/' . $name;
    file_put_contents($path, $content);
    return $path;
  }

  /**
   * Tests checking a CSV file, then importing it.
   */
  public function testCsv(): void {
    $this->createDomain('Taken.com', 1000);
    $file = $this->file('domains.csv', "Domain Name,Selling Price\nnew.com,100\ntaken.com,200\nbad,1\n");

    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand($file, ['dry-run' => TRUE, 'details' => TRUE]), 'A row with an error fails the command.');
    $output = $this->output->fetch();
    $this->assertStringContainsString('Dry run: nothing was saved.', $output);
    $this->assertMatchesRegularExpression('/To create\s+1/', $output);
    $this->assertMatchesRegularExpression('/Skipped\s+1/', $output);
    $this->assertMatchesRegularExpression('/4\s+bad\s+Not a registrable domain name\./', $output);
    $this->assertMatchesRegularExpression('/new\.com\s+to create/', $output);
    $this->assertMatchesRegularExpression('/taken\.com\s+skipped: in the catalog/', $output);
    $this->assertNull($this->product('new.com'), 'A dry run saves nothing.');

    $file = $this->file('domains.csv', "Domain Name,Selling Price\nnew.com,100\ntaken.com,200\nother.com,\n");
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand($file, [
      'update' => TRUE,
      'price' => '50',
      'currency' => 'eur',
      'buy-now' => FALSE,
      'unpublished' => TRUE,
    ]));
    $output = $this->output->fetch();
    $this->assertMatchesRegularExpression('/Created\s+2/', $output);
    $this->assertMatchesRegularExpression('/Updated\s+1/', $output);
    $this->assertEquals(new Price('100', 'EUR'), $this->variation('new.com')->getPrice());
    $this->assertEquals(new Price('50', 'EUR'), $this->variation('other.com')->getPrice());
    $this->assertFalse($this->product('new.com')->isPublished());
    $this->assertFalse((bool) $this->variation('new.com')->get('sale_buy_now')->value);
    $this->assertEquals(new Price('200', 'USD'), $this->variation('taken.com')->getPrice(), 'An update keeps the domain\'s currency.');
  }

  /**
   * Tests lists, read by the file's extension or as told.
   */
  public function testList(): void {
    $file = $this->file('names.txt', "a-list.com 10\nb-list.com\n");
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand($file, ['price' => '5']));
    $this->assertEquals(new Price('10', 'USD'), $this->variation('a-list.com')->getPrice());
    $this->assertEquals(new Price('5', 'USD'), $this->variation('b-list.com')->getPrice());

    $file = $this->file('names.dat', "c-list.com 10\n");
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand($file, ['format' => 'list']));
    $this->assertNotNull($this->product('c-list.com'));
  }

  /**
   * Tests importing the domains of a registrar account.
   */
  public function testRegistrar(): void {
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar', 'status' => FALSE])->save();
    $this->container->get('state')->set('commerce_domains_test.registrations', [
      'held.com' => 1_820_000_000,
      'listed.com' => NULL,
    ]);
    $this->createDomain('Listed.com');

    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand(NULL, ['from-registrar' => 'reg', 'price' => '10']));
    $this->assertSame(['Reg is disabled; enable it before importing from it.'], $this->messages('error'));

    $options = ['from-registrar' => 'reg', 'price' => '10', 'dry-run' => TRUE];
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand(NULL, $options), 'A dry run works while the provider is disabled.');
    $output = $this->output->fetch();
    $this->assertStringContainsString('2 domains in the Reg account; 1 not in the catalog.', $output);
    $this->assertMatchesRegularExpression('/To create\s+1/', $output);

    Provider::load('reg')->enable()->save();
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand(NULL, ['from-registrar' => 'reg', 'price' => '10']));
    $product = $this->product('held.com');
    $this->assertSame('reg', $product->get('domain_registrar')->target_id);
    $this->assertSame(1_820_000_000, (int) $product->get('domain_expires')->value);
  }

  /**
   * Tests what the command refuses before importing anything.
   */
  public function testRefusals(): void {
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand(NULL));
    $this->assertSame(['Name a file to import, or a registrar with --from-registrar.'], $this->messages('error'));

    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand($this->siteDirectory . '/missing.csv'));
    $this->assertStringStartsWith('Cannot read ', $this->messages('error')[0]);

    $file = $this->file('prices.csv', "price\n10\n");
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand($file));
    $this->assertStringStartsWith('No domain column', $this->messages('error')[0]);

    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand($file, ['format' => 'xml']));
    $this->assertSame(['The format is csv or list, not xml.'], $this->messages('error'));

    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand($file, ['type' => 'missing', 'currency' => 'XYZ']));
    $this->assertSame(['missing is not a product type with the Domain name trait.', 'XYZ is not an enabled currency.'], $this->messages('error'));

    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand(NULL, ['from-registrar' => 'nobody']));
    $this->assertSame(['No provider nobody with a registrar API.'], $this->messages('error'));
  }

}

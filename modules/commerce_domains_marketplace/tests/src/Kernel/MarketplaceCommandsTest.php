<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\Drush\Commands\MarketplaceCommands;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the marketplace sync Drush command.
 */
#[CoversClass(MarketplaceCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceCommandsTest extends MarketplaceKernelTestBase {

  use DrushCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->requireDrush();
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
    $this->prepareCommand($command);
    return $command->sync($provider, $options + ['domains' => NULL, 'dry-run' => FALSE, 'details' => FALSE]);
  }

  /**
   * Returns whether the domain is in stock here, as stored.
   */
  protected function isForSale(string $domain): bool {
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => $domain]);
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = reset($products);
    $variations = $product->getVariations();
    return (bool) reset($variations)->get('in_stock')->value;
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

  /**
   * Tests a sale the sync finds, and a sync that fails.
   */
  public function testSaleAndFailure(): void {
    $this->createProvider('test');
    $this->setListings(['same.com' => ['price' => 1000, 'status' => 'sold'], 'repriced.com' => ['price' => 1]]);
    // A dry run lists the sale and leaves it for the sync.
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('test', ['dry-run' => TRUE]));
    $this->assertMatchesRegularExpression('/Sold there\s+1\s+same\.com sold/', $this->output->fetch());
    $this->assertSame(['Test shows same.com sold (sold): a sync takes it off sale here.'], $this->messages('warning'));
    $this->assertSame([], $this->events());
    $this->assertTrue($this->isForSale('same.com'), 'A dry run changes nothing here.');

    $this->assertSame(DrushCommands::EXIT_SUCCESS, $this->runCommand('test'));
    $this->assertSame(['Test shows same.com sold (sold): it is now off sale here.'], $this->messages('warning'));
    $this->assertSame(MarketplaceEvents::SOLD_ELSEWHERE, $this->events()[0][0]);
    $this->assertFalse($this->isForSale('same.com'));

    $this->container->get('state')->set('commerce_domains_test.fail', 'permanent');
    $this->assertSame(DrushCommands::EXIT_FAILURE, $this->runCommand('test', ['dry-run' => TRUE]));
    $this->assertSame(['Test marketplace failed (permanent).'], $this->messages('error'));
    $this->assertSame([[MarketplaceEvents::SYNC_FAILED, 'test', ['' => 'Test marketplace failed (permanent).']]], $this->events());
  }

}

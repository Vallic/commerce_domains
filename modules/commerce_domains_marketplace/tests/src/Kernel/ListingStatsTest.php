<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\advancedqueue\Job;
use Drupal\commerce_domains_marketplace\Drush\Commands\StatsCommands;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\Plugin\AdvancedQueue\JobType\MarketplaceStatsJob;
use Drupal\commerce_domains_marketplace\Plugin\views\field\ProductListings;
use Drupal\commerce_domains_marketplace\StatsReader;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests reading listing views and leads into the recorded rows.
 */
#[CoversClass(StatsReader::class)]
#[CoversClass(MarketplaceStatsJob::class)]
#[CoversClass(SyncScheduler::class)]
#[CoversClass(ListingRecorder::class)]
#[CoversClass(ProductListings::class)]
#[CoversClass(StatsCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ListingStatsTest extends MarketplaceKernelTestBase {

  use DrushCommandTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views'];

  /**
   * Returns the test provider's rows, keyed by domain.
   */
  protected function rows(): array {
    return $this->container->get('database')->select(ListingRecorder::TABLE, 'l')
      ->fields('l')
      ->condition('provider', 'test')
      ->execute()
      ->fetchAllAssoc('domain');
  }

  /**
   * Tests cron queueing reads, the job, and the overview showing them.
   */
  public function testStats(): void {
    $this->createProvider('test');
    $this->createDomain('Busy.com', 1000);
    $this->createDomain('Quiet.com', 1000);
    $this->setListings(['busy.com' => ['price' => 1000], 'quiet.com' => ['price' => 1000]]);
    $this->container->get(MarketplaceSync::class)->plan(Provider::load('test'));
    $this->takeJobs();
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.stats', ['busy.com' => [12, 1]]);

    $scheduler = $this->container->get(SyncScheduler::class);
    $scheduler->queueStats();
    $jobs = $this->takeJobs();
    $this->assertCount(1, $jobs);
    $this->assertSame(SyncScheduler::STATS_JOB_TYPE, $jobs[0]->getType());
    $this->assertSame('Read 2 listings.', $this->process($jobs[0])->getMessage());
    $rows = $this->rows();
    $this->assertSame(12, (int) $rows['busy.com']->views);
    $this->assertSame(1, (int) $rows['busy.com']->leads);
    $this->assertNull($rows['busy.com']->offers, 'Not reported.');
    $this->assertSame(30, (int) $rows['busy.com']->stats_days);
    $this->assertNull($rows['quiet.com']->views);
    $this->assertNotNull($rows['quiet.com']->stats_checked, 'Read, though it reported nothing.');

    // Fresh counts need no read; a sync keeps them.
    $scheduler->queueStats();
    $this->assertSame([], $this->takeJobs());
    $state->set('commerce_domains_test.listings', ['busy.com' => ['price' => 900], 'quiet.com' => ['price' => 1000]]);
    $this->container->get(MarketplaceSync::class)->plan(Provider::load('test'));
    $this->assertSame(12, (int) $this->rows()['busy.com']->views);

    // A provider gone meanwhile is skipped.
    $this->assertSame('Skipped: the provider no longer reports listing stats.', $this->process(Job::create(SyncScheduler::STATS_JOB_TYPE, ['provider' => 'gone']))->getMessage());

    $this->installConfig(['system']);
    $this->setUpCurrentUser([], ['administer commerce_product']);
    $view = Views::getView('commerce_domains_overview');
    $view->setDisplay('page_1');
    $view->setExposedInput(['title' => 'busy']);
    $view->render();
    $this->assertStringContainsString('12 views, 1 lead in 30 days', strip_tags((string) $view->style_plugin->getField(0, 'commerce_domains_listings')));
    $view->destroy();
  }

  /**
   * Tests reading by hand with Drush.
   */
  public function testDrush(): void {
    $this->createProvider('test');
    $this->createDomain('Busy.com', 1000);
    $this->setListings(['busy.com' => ['price' => 1000]]);
    $this->container->get(MarketplaceSync::class)->plan(Provider::load('test'));
    $this->container->get('state')->set('commerce_domains_test.stats', ['busy.com' => [5, 0]]);

    $this->requireDrush();
    $command = new StatsCommands($this->container->get(StatsReader::class));
    $this->prepareCommand($command);
    $this->assertSame(0, $command->stats('test', ['domains' => NULL, 'limit' => 10]));
    $this->assertMatchesRegularExpression('/busy\.com\s+5\s+0\s+-\s+-\s+30 days/', $this->output->fetch());
    $this->assertSame(5, (int) $this->rows()['busy.com']->views);
    $this->assertSame(1, $command->stats('missing', ['domains' => NULL, 'limit' => 10]));
  }

}

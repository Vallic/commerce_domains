<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\Event\SyncFailedEvent;
use Drupal\commerce_domains_marketplace\EventSubscriber\JobFailureSubscriber;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests telling the site about failed syncs.
 */
#[CoversClass(JobFailureSubscriber::class)]
#[CoversClass(SyncFailedEvent::class)]
#[CoversClass(MarketplaceSync::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SyncFailureTest extends MarketplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createProvider('auto');
    $this->createDomain('New.com', 1500);
    $this->takeJobs();
  }

  /**
   * Queues a job and runs it through the queue, as cron would.
   *
   * @param string $type
   *   The job type.
   * @param int $retries
   *   How many times it was retried already.
   */
  protected function runQueued(string $type, int $retries): string {
    $queue = Queue::load(SyncScheduler::QUEUE);
    $payload = $type === SyncScheduler::JOB_TYPE ? ['provider' => 'auto', 'names' => ['new.com'], 'removed' => []] : ['provider' => 'auto'];
    $queue->enqueueJob(Job::create($type, $payload, ['num_retries' => $retries]));
    /** @var \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database $backend */
    $backend = $queue->getBackend();
    $job = $backend->claimJob();
    $this->container->get('advancedqueue.processor')->processJob($job, $queue);
    // A retried job would count as a duplicate of the next check.
    $backend->deleteJob($job->getId());
    return $job->getState();
  }

  /**
   * Tests queued pushes and checks: reported once they fail for good.
   */
  public function testQueuedFailures(): void {
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.fail', 'retryable');
    $failed = [MarketplaceEvents::SYNC_FAILED, 'auto', ['' => 'Test marketplace failed (retryable).']];

    // An outage is retried, and only reported when the retries run out.
    $this->assertSame(Job::STATE_QUEUED, $this->runQueued(SyncScheduler::JOB_TYPE, 0));
    $this->assertSame([], $this->events());
    $this->assertSame(Job::STATE_FAILURE, $this->runQueued(SyncScheduler::JOB_TYPE, 96));
    $this->assertSame([$failed], $this->events());

    // The same for the hourly check.
    $this->assertSame(Job::STATE_QUEUED, $this->runQueued(SyncScheduler::CHECK_JOB_TYPE, 95));
    $this->assertSame([], $this->events());
    $this->assertSame(Job::STATE_FAILURE, $this->runQueued(SyncScheduler::CHECK_JOB_TYPE, 96));
    $this->assertSame([$failed], $this->events());

    // A push the marketplace rejects outright isn't retried.
    $state->set('commerce_domains_test.fail', 'permanent');
    $this->assertSame(Job::STATE_FAILURE, $this->runQueued(SyncScheduler::JOB_TYPE, 0));
    $this->assertSame([[MarketplaceEvents::SYNC_FAILED, 'auto', ['' => 'Test marketplace failed (permanent).']]], $this->events());

    // Other jobs, and jobs of providers deleted since, are not ours to report.
    Provider::load('auto')->delete();
    $this->runQueued(SyncScheduler::JOB_TYPE, 96);
    $this->assertSame([], $this->events());
  }

  /**
   * Tests refused domains: reported when the refusal is new.
   */
  public function testRefusals(): void {
    $sync = $this->container->get(MarketplaceSync::class);
    $provider = Provider::load('auto');
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.reject', ['new.com']);

    $this->assertSame(['new.com' => 'Rejected by the test marketplace.'], $sync->sync($provider));
    $this->assertSame([[MarketplaceEvents::SYNC_FAILED, 'auto', ['new.com' => 'Rejected by the test marketplace.']]], $this->events());

    // Refused again for the same reason, e.g. by the next queued push.
    $sync->sync($provider, ['new.com']);
    $this->assertSame([], $this->events());

    // Once it went through, a new refusal is news again.
    $state->delete('commerce_domains_test.reject');
    $sync->sync($provider);
    $this->setListings([]);
    $state->set('commerce_domains_test.reject', ['new.com']);
    $sync->sync($provider);
    $this->assertCount(1, $this->events());

    // A failure of the whole push is the caller's to report.
    $state->delete('commerce_domains_test.reject');
    $plan = $sync->plan($provider);
    $state->set('commerce_domains_test.fail', 'permanent');
    try {
      $sync->apply($provider, $plan);
      $this->fail('The push should fail.');
    }
    catch (ProviderException) {
      // Expected.
    }
    $this->assertSame([], $this->events());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_domains_owner\EventSubscriber\MarketplaceSubscriber;
use Drupal\commerce_domains_owner\Notifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the owner hearing from real marketplace syncs.
 *
 * The owner module listens to the marketplace events by name, so it works
 * without the marketplace module; here both are on, and syncs, not the
 * test, dispatch the events.
 */
#[CoversClass(MarketplaceSubscriber::class)]
#[CoversClass(Notifier::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceSyncOwnerTest extends OwnerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['advancedqueue', 'commerce_domains_marketplace'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installSchema('commerce_domains_marketplace', [ListingRecorder::TABLE]);
    $this->installConfig(['commerce_domains_marketplace']);
  }

  /**
   * Creates a test marketplace that pushes automatically.
   */
  protected function createMarketplace(string $id): ProviderInterface {
    $provider = Provider::create([
      'id' => $id,
      'label' => ucfirst($id),
      'plugin' => 'test_marketplace',
      'status' => TRUE,
    ]);
    $provider->setThirdPartySetting('commerce_domains_marketplace', 'auto_sync', TRUE);
    $provider->save();
    return $provider;
  }

  /**
   * Returns a mail's body as one string.
   */
  protected static function body(array $mail): string {
    return preg_replace('/ +\n/', ' ', implode("\n", (array) $mail['body']));
  }

  /**
   * Tests that the owner subscribes to the events the marketplace defines.
   */
  public function testEventNames(): void {
    $this->assertSame([MarketplaceEvents::SOLD_ELSEWHERE, MarketplaceEvents::SYNC_FAILED], array_keys(MarketplaceSubscriber::getSubscribedEvents()));
  }

  /**
   * Tests a sale a check finds: recorded on the domain, and mailed once.
   */
  public function testSale(): void {
    $provider = $this->createMarketplace('selling');
    $product = $this->createDomain('Sold.com', 1000);
    $this->container->get('state')->set('commerce_domains_test.listings', [
      'sold.com' => ['price' => 1000, 'status' => 'pending'],
    ]);
    $sync = $this->container->get(MarketplaceSync::class);
    $this->assertSame(['sold.com'], $sync->plan($provider)->newSales);

    $mails = $this->ownerMails(Notifier::SOLD_ELSEWHERE);
    $this->assertCount(1, $mails);
    $this->assertSame('Sold on Selling: Sold.com', (string) $mails[0]['subject']);
    $this->assertStringContainsString('Selling reports Sold.com as pending.', self::body($mails[0]));
    $product = $this->reload($product);
    $this->assertTrue($product->get('domain_sold')->isEmpty(), 'No price, no sale: the owner enters it.');
    $this->assertFalse((bool) $this->variation($product)->get('in_stock')->value, 'Off sale here.');

    // Later checks see the same sale: no second mail.
    $this->container->get('state')->set('commerce_domains_test.listings', [
      'sold.com' => ['price' => 1000, 'status' => 'sold'],
    ]);
    $this->assertSame([], $sync->plan($provider)->newSales);
    $this->assertCount(1, $this->ownerMails(Notifier::SOLD_ELSEWHERE));
  }

  /**
   * Tests failing syncs: a refusal, then a queued push failing for good.
   */
  public function testFailures(): void {
    $provider = $this->createMarketplace('auto');
    $this->createDomain('New.com', 1500);
    $state = $this->container->get('state');

    // The marketplace refuses the domain.
    $state->set('commerce_domains_test.reject', ['new.com']);
    $this->container->get(MarketplaceSync::class)->sync($provider);
    $mails = $this->ownerMails(Notifier::SYNC_FAILED);
    $this->assertCount(1, $mails);
    $this->assertSame('Auto sync failed', (string) $mails[0]['subject']);
    $this->assertStringContainsString("Auto refused 1 domain:\n- new.com: Rejected by the test marketplace.", self::body($mails[0]));

    // A queued push that fails for good, the same day: recorded for the
    // dashboard, not mailed again.
    $state->delete('commerce_domains_test.reject');
    $state->set('commerce_domains_test.fail', 'permanent');
    $queue = Queue::load(SyncScheduler::QUEUE);
    $payload = ['provider' => 'auto', 'names' => ['new.com'], 'removed' => []];
    $queue->enqueueJob(Job::create(SyncScheduler::JOB_TYPE, $payload));
    /** @var \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database $backend */
    $backend = $queue->getBackend();
    $job = $backend->claimJob();
    $this->container->get('advancedqueue.processor')->processJob($job, $queue);
    $this->assertSame(Job::STATE_FAILURE, $job->getState());
    $this->assertCount(1, $this->ownerMails(Notifier::SYNC_FAILED));
    $this->assertSame('Test marketplace failed (permanent).', $this->container->get(Notifier::class)->lastSyncFailure('auto')['message']);
  }

}

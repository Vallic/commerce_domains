<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\EventSubscriber\CatalogSubscriber;
use Drupal\commerce_domains_marketplace\Hook\CronHooks;
use Drupal\commerce_domains_marketplace\Hook\MarketplaceHooks;
use Drupal\commerce_domains_marketplace\Plugin\AdvancedQueue\JobType\MarketplaceCheckJob;
use Drupal\commerce_domains_marketplace\Plugin\AdvancedQueue\JobType\MarketplaceSyncJob;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pushing catalog changes through the queue, and the settings form.
 */
#[CoversClass(SyncScheduler::class)]
#[CoversClass(CatalogSubscriber::class)]
#[CoversClass(MarketplaceSyncJob::class)]
#[CoversClass(MarketplaceCheckJob::class)]
#[CoversClass(CronHooks::class)]
#[CoversClass(MarketplaceHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class AutomaticSyncTest extends MarketplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createProvider('auto');
    $this->createProvider('manual', TRUE, FALSE);
    $this->createProvider('off', FALSE, TRUE);
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
  }

  /**
   * Ends the request the way the kernel does, queueing collected changes.
   *
   * @return list<array>
   *   The queued jobs' payloads.
   */
  protected function queued(): array {
    $this->container->get(SyncScheduler::class)->destruct();
    /** @var \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database $backend */
    $backend = Queue::load(SyncScheduler::QUEUE)->getBackend();
    $payloads = [];
    while ($job = $backend->claimJob()) {
      $this->assertSame(SyncScheduler::JOB_TYPE, $job->getType());
      $payloads[] = $job->getPayload();
      $backend->deleteJob($job->getId());
    }
    return $payloads;
  }

  /**
   * Tests what is queued for added, renamed, repriced and deleted domains.
   */
  public function testQueuedChanges(): void {
    $product = $this->createDomain('New.com');
    $this->assertSame([['provider' => 'auto', 'names' => ['new.com'], 'removed' => []]], $this->queued(), 'Only enabled providers with automatic push get items.');

    $product->setTitle('Renamed.com')->save();
    $this->assertSame([['provider' => 'auto', 'names' => ['renamed.com'], 'removed' => ['new.com']]], $this->queued());

    // getDefaultVariation() checks access, and the test runs as anonymous.
    $variation = $product->getVariations()[0];
    $variation->set('in_stock', FALSE)->save();
    $this->assertSame([['provider' => 'auto', 'names' => ['renamed.com'], 'removed' => []]], $this->queued());

    $product->delete();
    $this->assertContains('renamed.com', array_merge(...array_column($this->queued(), 'removed')));

    ProductType::create(['id' => 'merch', 'label' => 'Merch', 'variationTypes' => ['default']])->save();
    Product::create(['type' => 'merch', 'title' => 'A shirt', 'stores' => [$this->store]])->save();
    $this->assertSame([], $this->queued(), 'Other products are not synced.');
  }

  /**
   * Tests that saves leaving the listing as it was queue nothing.
   */
  public function testUnchangedListing(): void {
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $product = $this->createDomain('Same.com');
    $this->queued();
    $variation = $product->getVariations()[0];

    $product->set('domain_expires', 1900000000)->save();
    $variation->setSku('same-com-2')->save();
    $product->save();
    $this->assertSame([], $this->queued(), 'Fields listings do not read change nothing.');

    $variation->set('sale_floor_price', ['number' => '500', 'currency_code' => 'USD'])->save();
    $this->assertSame([['provider' => 'auto', 'names' => ['same.com'], 'removed' => []]], $this->queued());

    $product->set('domain_internal', TRUE)->save();
    $this->assertSame([['provider' => 'auto', 'names' => ['same.com'], 'removed' => []]], $this->queued());
  }

  /**
   * Tests the job pushing changes, and retrying on outages.
   */
  public function testJob(): void {
    $this->createDomain('New.com', 1500);
    $job_type = $this->container->get('plugin.manager.advancedqueue_job_type')->createInstance(SyncScheduler::JOB_TYPE);
    $process = fn(string $provider): JobResult => $job_type->process(Job::create(SyncScheduler::JOB_TYPE, [
      'provider' => $provider,
      'names' => ['new.com'],
      'removed' => [],
    ]));

    $this->assertSame(Job::STATE_SUCCESS, $process('auto')->getState());
    $this->assertSame(['new.com' => ['price' => 1500]], $this->listings());

    // Providers turned off since the job was queued are skipped.
    $this->container->get('state')->delete('commerce_domains_test.calls');
    foreach (['manual', 'off', 'missing'] as $provider) {
      $this->assertSame(Job::STATE_SUCCESS, $process($provider)->getState());
    }
    $this->assertSame([], $this->calls());

    // A refused domain is reported on the job.
    $this->setListings([]);
    $this->container->get('state')->set('commerce_domains_test.reject', ['new.com']);
    $this->assertSame('1 of the domains refused.', $process('auto')->getMessage());
    $this->container->get('state')->set('commerce_domains_test.reject', []);

    // A permanent failure isn't retried.
    $this->container->get('state')->set('commerce_domains_test.fail', 'permanent');
    $result = $process('auto');
    $this->assertSame(Job::STATE_FAILURE, $result->getState());
    $this->assertSame(0, $result->getMaxRetries());

    // An outage is, with the job type's retries.
    $this->container->get('state')->set('commerce_domains_test.fail', 'retryable');
    $result = $process('auto');
    $this->assertSame(Job::STATE_FAILURE, $result->getState());
    $this->assertNull($result->getMaxRetries());
    $this->assertSame(96, $job_type->getMaxRetries());
  }

  /**
   * Tests the hourly sale checks cron queues.
   */
  public function testHourlyChecks(): void {
    $product = $this->createDomain('Sold.com', 1000);
    $this->setListings(['sold.com' => ['price' => 1000, 'status' => 'sold']]);
    $this->takeJobs();
    /** @var \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database $backend */
    $backend = Queue::load(SyncScheduler::QUEUE)->getBackend();
    $state = $this->container->get('state');

    // Only the provider that pushes automatically gets one, once an hour.
    $this->container->get('module_handler')->invoke('commerce_domains_marketplace', 'cron');
    $this->container->get(SyncScheduler::class)->queueChecks();
    $this->assertSame(1, (int) $backend->countJobs()[Job::STATE_QUEUED]);
    // An hour on, the first one still waits, e.g. retrying an outage.
    $state->set(SyncScheduler::CHECKED_KEY . 'auto', 0);
    $this->container->get(SyncScheduler::class)->queueChecks();
    $this->assertSame(1, (int) $backend->countJobs()[Job::STATE_QUEUED]);

    $jobs = $this->takeJobs();
    $this->assertSame(SyncScheduler::CHECK_JOB_TYPE, $jobs[0]->getType());
    $this->assertSame(['provider' => 'auto'], $jobs[0]->getPayload());
    $result = $this->process($jobs[0]);
    $this->assertSame(Job::STATE_SUCCESS, $result->getState());
    $this->assertSame('Sold there: sold.com.', $result->getMessage());
    $this->assertSame([self::soldEvent('auto', 'sold.com', (int) $product->id(), 'sold')], $this->events());
    $this->assertSame([['fetch', NULL]], $this->calls(), 'A check pushes nothing.');

    // Providers that stopped pushing automatically are skipped.
    $this->container->get('state')->delete('commerce_domains_test.calls');
    foreach (['manual', 'off', 'missing'] as $provider) {
      $result = $this->process(Job::create(SyncScheduler::CHECK_JOB_TYPE, ['provider' => $provider]));
      $this->assertSame(Job::STATE_SUCCESS, $result->getState());
    }
    $this->assertSame([], $this->calls());
    $provider = Provider::load('auto');
    $provider->delete();
    $this->assertNull($state->get(SyncScheduler::CHECKED_KEY . 'auto'), 'Forgotten with the provider.');
  }

  /**
   * Tests moving waiting pushes from the core queue.
   */
  public function testUpdate(): void {
    $old = $this->container->get('queue')->get('commerce_domains_marketplace_sync');
    $old->createItem(['provider' => 'auto', 'names' => ['waiting.com'], 'removed' => []]);
    $this->container->get('module_handler')->loadInclude('commerce_domains_marketplace', 'install');
    commerce_domains_marketplace_update_11003();
    $this->assertSame(0, $old->numberOfItems());
    $this->assertSame([['provider' => 'auto', 'names' => ['waiting.com'], 'removed' => []]], $this->queued());
  }

  /**
   * Tests the sync settings on the provider form.
   */
  public function testSettingsForm(): void {
    $form_builder = $this->container->get('entity.form_builder');
    $form = $form_builder->getForm(Provider::load('manual'), 'edit');
    $this->assertSame(0, (int) $form['marketplace']['auto_sync']['#default_value']);
    $this->assertSame(1, (int) $form['marketplace']['delete_unlisted']['#default_value']);
    $this->assertArrayNotHasKey('marketplace', $form_builder->getForm(Provider::load('gname'), 'edit'));

    $form_object = $this->entityTypeManager->getFormObject('commerce_domains_provider', 'edit')->setEntity(Provider::load('manual'));
    $form_state = (new FormState())->setValues([
      'label' => 'Manual',
      'status' => 1,
      'auto_sync' => 1,
      // A programmatic submit unchecks a checkbox with NULL.
      'delete_unlisted' => NULL,
      'configuration' => ['price_factor' => '1.1', 'premium_price_factor' => '1.2'],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $this->assertSame(
      ['auto_sync' => TRUE, 'delete_unlisted' => FALSE],
      $this->config('commerce_domains.provider.manual')->get('third_party_settings.commerce_domains_marketplace'),
    );
    $this->assertSame(1.1, Provider::load('manual')->getPlugin()->getConfiguration()['price_factor']);
  }

}

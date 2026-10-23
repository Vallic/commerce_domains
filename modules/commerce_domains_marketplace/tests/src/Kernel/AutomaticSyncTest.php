<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\EventSubscriber\CatalogSubscriber;
use Drupal\commerce_domains_marketplace\Hook\MarketplaceHooks;
use Drupal\commerce_domains_marketplace\Plugin\QueueWorker\MarketplaceSyncWorker;
use Drupal\commerce_domains_marketplace\SyncScheduler;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\Core\Form\FormState;
use Drupal\Core\Queue\DelayedRequeueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pushing catalog changes through the queue, and the settings form.
 */
#[CoversClass(SyncScheduler::class)]
#[CoversClass(CatalogSubscriber::class)]
#[CoversClass(MarketplaceSyncWorker::class)]
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
   *   The queued items' data.
   */
  protected function queued(): array {
    $this->container->get(SyncScheduler::class)->destruct();
    $queue = $this->container->get('queue')->get(SyncScheduler::QUEUE);
    $items = [];
    while (is_object($item = $queue->claimItem())) {
      $items[] = $item->data;
      $queue->deleteItem($item);
    }
    return $items;
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
   * Tests the worker pushing an item, and postponing on outages.
   */
  public function testWorker(): void {
    $this->createDomain('New.com', 1500);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(SyncScheduler::QUEUE);

    $worker->processItem(['provider' => 'auto', 'names' => ['new.com'], 'removed' => []]);
    $this->assertSame(['new.com' => ['price' => 1500]], $this->listings());

    // Providers turned off since the item was queued are skipped.
    $this->container->get('state')->delete('commerce_domains_test.calls');
    $worker->processItem(['provider' => 'manual', 'names' => ['new.com'], 'removed' => []]);
    $worker->processItem(['provider' => 'off', 'names' => ['new.com'], 'removed' => []]);
    $worker->processItem(['provider' => 'missing', 'names' => ['new.com'], 'removed' => []]);
    $this->assertSame([], $this->calls());

    // A permanent failure is logged and the item dropped.
    $this->container->get('state')->set('commerce_domains_test.fail', 'permanent');
    $worker->processItem(['provider' => 'auto', 'names' => ['new.com'], 'removed' => []]);

    // An outage keeps the item for later.
    $this->container->get('state')->set('commerce_domains_test.fail', 'retryable');
    $this->expectException(DelayedRequeueException::class);
    $worker->processItem(['provider' => 'auto', 'names' => ['new.com'], 'removed' => []]);
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

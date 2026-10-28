<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\Hook\ListingHooks;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_domains_marketplace\Plugin\views\field\ProductListings;
use Drupal\commerce_domains_marketplace\Plugin\views\field\RegistrarCheck;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests recording listings, and the domain overview built on them.
 */
#[CoversClass(ListingRecorder::class)]
#[CoversClass(ListingHooks::class)]
#[CoversClass(ProductListings::class)]
#[CoversClass(RegistrarCheck::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ListingRecorderTest extends MarketplaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views'];

  /**
   * The sync service.
   *
   * @var \Drupal\commerce_domains_marketplace\MarketplaceSync
   */
  protected MarketplaceSync $sync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sync = $this->container->get(MarketplaceSync::class);
    $this->createDomain('Same.com', 1000);
    $this->createDomain('Repriced.com', 2000);
    $this->createDomain('New.com', 3000);
    $this->createDomain('Gone.com', 4000, [], ['in_stock' => FALSE]);
    $this->createDomain('Sold.com', 5000);
    $this->createDomain('Premium.com', 6000, ['atom_premium' => TRUE]);
    $this->createDomain('Quiet.com', 7000, [], ['in_stock' => FALSE]);
    $this->setListings([
      'same.com' => ['price' => 1000],
      'repriced.com' => ['price' => 1],
      'gone.com' => ['price' => 4000],
      'sold.com' => ['price' => 5000, 'status' => 'sold'],
      'premium.com' => ['price' => 1],
      'foreign.com' => ['price' => 1],
    ]);
    $this->createProvider('test');
  }

  /**
   * Returns the recorded rows, keyed by domain.
   *
   * @return array<string, object>
   *   The rows.
   */
  protected function rows(string $provider = 'test'): array {
    return $this->container->get('database')->select(ListingRecorder::TABLE, 'l')
      ->fields('l')
      ->condition('provider', $provider)
      ->orderBy('domain')
      ->execute()
      ->fetchAllAssoc('domain');
  }

  /**
   * Returns each row's status and note.
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   Status and note, keyed by domain.
   */
  protected function statuses(string $provider = 'test'): array {
    return array_map(fn(object $row) => [$row->status, $row->note], $this->rows($provider));
  }

  /**
   * Tests what a check records, then a push.
   */
  public function testCheckAndPush(): void {
    $plan = $this->sync->plan(Provider::load('test'));
    $this->assertSame([
      'gone.com' => ['outdated', 'No longer for sale; to be removed.'],
      'new.com' => ['outdated', 'Not listed yet.'],
      'premium.com' => ['unmanaged', NULL],
      'repriced.com' => ['outdated', 'price 1 → 2000'],
      'same.com' => ['listed', NULL],
      'sold.com' => ['locked', 'sold'],
    ], $this->statuses(), 'Names the catalog lacks, and names neither listed nor for sale, get no row.');
    $rows = $this->rows();
    $this->assertSame('{"price":2000}', $rows['repriced.com']->listing, 'The listing wanted.');
    $this->assertSame('{"price":1}', $rows['premium.com']->listing, 'The listing found.');
    $this->assertNull($rows['same.com']->pushed);
    $this->assertGreaterThan(0, (int) $rows['same.com']->checked);
    $this->assertNotNull($rows['same.com']->product_id);

    $this->container->get('state')->set('commerce_domains_test.reject', ['new.com']);
    $this->sync->apply(Provider::load('test'), $plan);
    $this->assertSame([
      'gone.com' => ['unlisted', NULL],
      'new.com' => ['failed', 'Rejected by the test marketplace.'],
      'premium.com' => ['unmanaged', NULL],
      'repriced.com' => ['listed', NULL],
      'same.com' => ['listed', NULL],
      'sold.com' => ['locked', 'sold'],
    ], $this->statuses());
    $rows = $this->rows();
    $this->assertNotNull($rows['repriced.com']->pushed);
    $this->assertNotNull($rows['gone.com']->pushed);
    $this->assertNull($rows['new.com']->pushed);

    // The failure stays on record until the push is made again; the removed
    // listing keeps its row, saying when it went.
    $this->sync->plan(Provider::load('test'));
    $this->assertSame(['failed', 'Rejected by the test marketplace.'], $this->statuses()['new.com']);
    $this->assertSame('unlisted', $this->statuses()['gone.com'][0]);

    $this->container->get('state')->set('commerce_domains_test.reject', []);
    $this->sync->sync(Provider::load('test'), ['new.com']);
    $this->assertSame(['listed', NULL], $this->statuses()['new.com']);
  }

  /**
   * Tests a push that fails as a whole.
   */
  public function testFailedPush(): void {
    $plan = $this->sync->plan(Provider::load('test'));
    $this->container->get('state')->set('commerce_domains_test.fail', 'retryable');
    try {
      $this->sync->apply(Provider::load('test'), $plan);
      $this->fail('The push should fail.');
    }
    catch (ProviderException) {
    }
    $statuses = $this->statuses();
    $this->assertSame(['failed', 'Test marketplace failed (retryable).'], $statuses['new.com']);
    $this->assertSame(['failed', 'Test marketplace failed (retryable).'], $statuses['gone.com']);
    $this->assertSame(['listed', NULL], $statuses['same.com']);
  }

  /**
   * Tests that removal turned off is noted, and stale rows go.
   */
  public function testHousekeeping(): void {
    $provider = $this->createProvider('keep', TRUE, FALSE, FALSE);
    $this->sync->sync($provider);
    $this->assertSame(['outdated', 'No longer for sale; removal is turned off.'], $this->statuses('keep')['gone.com']);

    // A deleted product's rows lose the link, and go once neither side has
    // the name.
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => 'same.com']);
    reset($products)->delete();
    $this->assertNull($this->rows('keep')['same.com']->product_id);
    $this->setListings(['repriced.com' => ['price' => 2000]]);
    $this->sync->plan($provider);
    $this->assertArrayNotHasKey('same.com', $this->rows('keep'));

    // A check of a few names leaves the other rows alone.
    $count = count($this->rows('keep'));
    $this->sync->plan($provider, ['repriced.com']);
    $this->assertCount($count, $this->rows('keep'));

    $provider->delete();
    $this->assertSame([], $this->rows('keep'));
  }

  /**
   * Tests the domain overview and the listings report.
   */
  public function testViews(): void {
    // Product queries are access-checked.
    $this->setUpCurrentUser([], ['administer commerce_product']);
    $this->sync->plan(Provider::load('test'));

    $view = Views::getView('commerce_domains_overview');
    $this->assertNotNull($view, 'The overview installs with Views.');
    $view->setDisplay('page_1');
    $view->setExposedInput(['title' => 'same']);
    // Rendering runs the fields' preRender(), which reads the listings.
    $view->render();
    $this->assertCount(1, $view->result);
    $this->assertSame('Test: Listed', trim(strip_tags((string) $view->style_plugin->getField(0, 'commerce_domains_listings'))));
    $view->destroy();

    // Quiet.com isn't listed and needn't be: it has no row, and shows none.
    $view = Views::getView('commerce_domains_overview');
    $view->setDisplay('page_1');
    $view->setExposedInput(['title' => 'quiet']);
    // Rendering runs the fields' preRender(), which reads the listings.
    $view->render();
    $this->assertCount(1, $view->result);
    $this->assertSame('', trim(strip_tags((string) $view->style_plugin->getField(0, 'commerce_domains_listings'))));
    $this->assertSame('', trim((string) $view->style_plugin->getField(0, 'commerce_domains_registrar_check')), 'No registrar recorded.');
    $view->destroy();

    $view = Views::getView('commerce_domains_listings');
    $view->setDisplay('page_1');
    $view->setExposedInput(['status' => 'outdated']);
    $view->execute();
    $domains = array_map(fn($row) => (string) $view->field['domain']->getValue($row), $view->result);
    sort($domains);
    $this->assertSame(['gone.com', 'new.com', 'repriced.com'], $domains);
    $view->destroy();

    // The listings tab gets an Overview tab beside it.
    $tasks = $this->container->get('plugin.manager.menu.local_task')->getDefinitions();
    $this->assertSame('view.commerce_domains_overview.page_1', $tasks['commerce_domains_marketplace.overview']['route_name']);
  }

}

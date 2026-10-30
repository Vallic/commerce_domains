<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent;
use Drupal\commerce_domains_marketplace\Event\SyncFailedEvent;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_owner\EventSubscriber\MarketplaceSubscriber;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\SaleRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the notifications about marketplace sales and failing syncs.
 */
#[CoversClass(MarketplaceSubscriber::class)]
#[CoversClass(Notifier::class)]
#[CoversClass(SaleRecorder::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceNotificationsTest extends OwnerKernelTestBase {

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
   * Dispatches an event by its name.
   */
  protected function dispatch(object $event, string $name): void {
    $this->container->get('event_dispatcher')->dispatch($event, $name);
  }

  /**
   * Returns the body of a mail as one string, unwrapped.
   */
  protected static function body(array $mail): string {
    return preg_replace('/ +\n/', ' ', implode("\n", (array) $mail['body']));
  }

  /**
   * Tests a sale a marketplace reports.
   */
  public function testSoldElsewhere(): void {
    $provider = $this->createProvider('afternic', 'test_marketplace');
    $product = $this->createDomain('Market.com');
    $this->dispatch(new SoldElsewhereEvent($provider, 'market.com', $product, 'SOLD'), 'commerce_domains_marketplace.sold_elsewhere');

    $mails = $this->ownerMails(Notifier::SOLD_ELSEWHERE);
    $this->assertCount(1, $mails);
    $this->assertSame('Sold on Afternic: Market.com', (string) $mails[0]['subject']);
    $body = self::body($mails[0]);
    $this->assertStringContainsString('Afternic reports Market.com as SOLD.', $body);
    $this->assertStringContainsString('for a lease to own the number of monthly payments', $body);
    $this->assertStringContainsString($product->toUrl('edit-form', ['absolute' => TRUE])->toString(), $body);
    $product = $this->reload($product);
    $this->assertSame($this->now, (int) $product->get('domain_sold')->value);
    $this->assertSame('afternic', $product->get('domain_sold_via')->target_id);
    $this->assertTrue($product->get('domain_sale_price')->isEmpty(), 'The owner enters what it brought.');

    // A later report keeps the first sale; a domain gone from the catalog
    // still mails.
    $this->dispatch(new SoldElsewhereEvent($this->createProvider('sedo', 'test_marketplace'), 'market.com', $product, 'SOLD'), 'commerce_domains_marketplace.sold_elsewhere');
    $this->assertSame('afternic', $this->reload($product)->get('domain_sold_via')->target_id);
    $this->dispatch(new SoldElsewhereEvent($provider, 'gone.com', NULL, 'pending sale'), 'commerce_domains_marketplace.sold_elsewhere');
    $mails = $this->ownerMails(Notifier::SOLD_ELSEWHERE);
    $this->assertCount(3, $mails);
    $this->assertStringContainsString("Afternic reports gone.com as pending sale.\n\nIt is no longer in the catalog.", self::body($mails[2]));

    // Turned off, the sale is still recorded.
    $this->config('commerce_domains_owner.settings')->set('notify.sold_elsewhere', FALSE)->save();
    $quiet = $this->createDomain('Quiet.com');
    $this->dispatch(new SoldElsewhereEvent($provider, 'quiet.com', $quiet, 'SOLD'), 'commerce_domains_marketplace.sold_elsewhere');
    $this->assertCount(3, $this->ownerMails(Notifier::SOLD_ELSEWHERE));
    $this->assertFalse($this->reload($quiet)->get('domain_sold')->isEmpty());
  }

  /**
   * Tests failing syncs, mailed once a day for each marketplace.
   */
  public function testSyncFailed(): void {
    $afternic = $this->createProvider('afternic', 'test_marketplace');
    $sedo = $this->createProvider('sedo', 'test_marketplace');
    $this->dispatch(new SyncFailedEvent($afternic, ['' => 'Afternic is down.']), 'commerce_domains_marketplace.sync_failed');
    $mails = $this->ownerMails(Notifier::SYNC_FAILED);
    $this->assertCount(1, $mails);
    $this->assertSame('Afternic sync failed', (string) $mails[0]['subject']);
    $this->assertStringContainsString('The sync with Afternic failed: Afternic is down.', self::body($mails[0]));
    $this->assertStringContainsString('/admin/commerce/domains/listings', self::body($mails[0]));

    // The next failure that day is recorded, not mailed; another
    // marketplace mails.
    $this->dispatch(new SyncFailedEvent($afternic, ['a.com' => 'Bad price.', 'b.com' => 'Taken.']), 'commerce_domains_marketplace.sync_failed');
    $this->assertCount(1, $this->ownerMails(Notifier::SYNC_FAILED));
    $notifier = $this->container->get(Notifier::class);
    $this->assertSame('2 domains refused.', $notifier->lastSyncFailure('afternic')['message']);
    $this->dispatch(new SyncFailedEvent($sedo, ['c.com' => 'Too short.']), 'commerce_domains_marketplace.sync_failed');
    $mails = $this->ownerMails(Notifier::SYNC_FAILED);
    $this->assertCount(2, $mails);
    $this->assertStringContainsString("Sedo refused 1 domain:\n- c.com: Too short.", self::body($mails[1]));

    // A day later it mails again.
    $state = $this->container->get('state');
    $failures = $state->get(Notifier::SYNC_FAILURES_KEY);
    $failures['afternic']['mailed'] -= Notifier::SYNC_FAILED_INTERVAL;
    $state->set(Notifier::SYNC_FAILURES_KEY, $failures);
    $this->dispatch(new SyncFailedEvent($afternic, ['a.com' => 'Bad price.']), 'commerce_domains_marketplace.sync_failed');
    $this->assertCount(3, $this->ownerMails(Notifier::SYNC_FAILED));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains_marketplace\Form\SyncForm;
use Drupal\commerce_domains_marketplace\Hook\ListingHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests checking and syncing whole marketplaces from the browser.
 */
#[CoversClass(SyncForm::class)]
#[CoversClass(ListingHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SyncFormTest extends MarketplaceKernelTestBase {

  /**
   * Tests the batch step for one marketplace, checking and then syncing.
   */
  public function testRun(): void {
    $this->createProvider('market');
    $this->createDomain('New.com', 2000);
    $this->setListings([]);

    $context = ['results' => []];
    SyncForm::run('market', FALSE, $context);
    $this->assertSame([], $this->listings(), 'A check pushes nothing.');
    $this->assertStringContainsString('1 to add', (string) $context['results']['done'][0]);

    $context = ['results' => []];
    SyncForm::run('market', TRUE, $context);
    $this->assertArrayHasKey('new.com', $this->listings());
    $this->assertStringContainsString('1 added', (string) $context['results']['done'][0]);

    $context = ['results' => []];
    SyncForm::run('gone', TRUE, $context);
    $this->assertSame([], $context['results'], 'A provider deleted meanwhile is skipped.');
  }

}

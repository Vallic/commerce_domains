<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains_marketplace\Event\SoldElsewhereEvent;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Drush\Commands\MarketplaceSalesCommands;
use Drupal\commerce_domains_owner\EventSubscriber\MarketplaceSubscriber;
use Drupal\commerce_domains_owner\MarketplaceSales;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests filling in sales from marketplaces' sales reports.
 */
#[CoversClass(MarketplaceSales::class)]
#[CoversClass(MarketplaceSalesCommands::class)]
#[CoversClass(MarketplaceSubscriber::class)]
#[CoversClass(SaleRecorder::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class MarketplaceSalesTest extends OwnerKernelTestBase {

  use DrushCommandTrait;

  // When the reported sales were made.
  protected const SOLD = 1775820276;

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
   * Sets the sales the test marketplace reports.
   *
   * @param array<string, array{0: int|null, 1: float|null, 2: int|null}> $sales
   *   When each sold, what it brought and its lease months, by domain.
   */
  protected function setSales(array $sales): void {
    $this->container->get('state')->set('commerce_domains_test.sales', $sales);
  }

  /**
   * Asserts what a domain sold for, in dollars.
   */
  protected function assertPrice(string $expected, ProductInterface $product, string $message = ''): void {
    $price = Costs::price($product, 'domain_sale_price');
    $this->assertNotNull($price, $message);
    $this->assertSame('USD', $price->getCurrencyCode(), $message);
    $this->assertTrue($price->equals(new Price($expected, 'USD')), $message ?: "Sold for $expected.");
  }

  /**
   * Tests a sale found on the marketplace, filled in from its report.
   */
  public function testSoldElsewhere(): void {
    $provider = $this->createProvider('market', 'test_atom');
    $product = $this->createDomain('Lease.com');
    $this->setSales(['lease.com' => [self::SOLD, 3360.0, 6]]);
    $this->container->get('event_dispatcher')->dispatch(new SoldElsewhereEvent($provider, 'lease.com', $product, 'Transfer Complete'), 'commerce_domains_marketplace.sold_elsewhere');

    $product = $this->reload($product);
    $this->assertSame(self::SOLD, (int) $product->get('domain_sold')->value, 'The sale date, not when it was found.');
    $this->assertSame('market', $product->get('domain_sold_via')->target_id);
    $this->assertPrice('3360', $product);
    $this->assertSame(6, (int) $product->get('domain_lease_months')->value);

    // No sale is recorded before the report says what it brought, nor when
    // it can't be read: cron records it later.
    $this->setSales(['pending.com' => [self::SOLD, NULL, NULL]]);
    $pending = $this->createDomain('Pending.com');
    $this->container->get('event_dispatcher')->dispatch(new SoldElsewhereEvent($provider, 'pending.com', $pending, 'Transfer Complete'), 'commerce_domains_marketplace.sold_elsewhere');
    $this->assertTrue($this->reload($pending)->get('domain_sold')->isEmpty());
    $this->container->get('state')->set('commerce_domains_test.fail', 'retryable');
    $other = $this->createDomain('Other.com');
    $this->container->get('event_dispatcher')->dispatch(new SoldElsewhereEvent($provider, 'other.com', $other, 'SOLD'), 'commerce_domains_marketplace.sold_elsewhere');
    $this->assertTrue($this->reload($other)->get('domain_sold')->isEmpty());
  }

  /**
   * Tests the daily fill, what it leaves alone, and Drush.
   */
  public function testFill(): void {
    $this->createProvider('market', 'test_atom');
    // Off sale, with no sale recorded: the sync found it sold.
    $found = $this->createDomain('Found.com', 1000, [], ['in_stock' => FALSE]);
    $entered = $this->createDomain('Entered.com', 1000, [
      'domain_sold' => $this->now,
      'domain_sold_via' => 'market',
      'domain_sale_price' => new Price('500', 'USD'),
    ]);
    $site = $this->createDomain('Site.com', 1000, [
      'domain_sold' => $this->now,
      'domain_sale_order' => 7,
      'domain_sale_price' => new Price('900', 'USD'),
    ]);
    $elsewhere = $this->createDomain('Elsewhere.com', 1000, [
      'domain_sold' => $this->now,
      'domain_sold_via' => 'market_other',
      'domain_sale_price' => new Price('900', 'USD'),
    ]);
    $off = $this->createDomain('Off.com', 1000, [], ['in_stock' => FALSE]);
    $selling = $this->createDomain('Selling.com');
    $this->setSales([
      'found.com' => [self::SOLD, 700.0, NULL],
      'entered.com' => [self::SOLD, 700.0, NULL],
      'site.com' => [self::SOLD, 700.0, NULL],
      'elsewhere.com' => [self::SOLD, 700.0, NULL],
      'off.com' => [self::SOLD, NULL, NULL],
      'selling.com' => [self::SOLD, 700.0, NULL],
      'gone.com' => [self::SOLD, 700.0, NULL],
    ]);

    $this->requireDrush();
    $command = new MarketplaceSalesCommands($this->container->get(MarketplaceSales::class), $this->container->get('date.formatter'));
    $this->prepareCommand($command);
    $this->assertSame(0, $command->sales('market', ['domains' => NULL, 'dry-run' => TRUE]));
    $output = $this->output->fetch();
    $this->assertMatchesRegularExpression('/found\.com .*domain_sold: 2026-04-10, domain_sold_via: market, domain_sale_price: USD 700/', $output);
    $this->assertMatchesRegularExpression('/gone\.com .*not in the catalog/', $output);
    $this->assertMatchesRegularExpression('/selling\.com .*nothing to fill in/', $output);
    $this->assertTrue($this->reload($found)->get('domain_sale_price')->isEmpty(), 'A dry run changes nothing.');
    $this->assertSame(1, $command->sales('missing', ['domains' => NULL, 'dry-run' => TRUE]));

    // Cron fills in once a day.
    $sales = $this->container->get(MarketplaceSales::class);
    $this->assertSame(2, $sales->cron());
    $this->assertSame(0, $sales->cron(), 'Read once a day.');

    $found = $this->reload($found);
    $this->assertSame(self::SOLD, (int) $found->get('domain_sold')->value);
    $this->assertSame('market', $found->get('domain_sold_via')->target_id);
    $this->assertPrice('700', $found);
    $entered = $this->reload($entered);
    $this->assertPrice('500', $entered, "The owner's entry stands.");
    $this->assertSame(self::SOLD, (int) $entered->get('domain_sold')->value);
    $this->assertSame($this->now, (int) $this->reload($site)->get('domain_sold')->value, 'A sale on this site stands.');
    $this->assertTrue($this->reload($site)->get('domain_sold_via')->isEmpty());
    $this->assertSame($this->now, (int) $this->reload($elsewhere)->get('domain_sold')->value, 'A sale on another marketplace stands.');
    $this->assertTrue($this->reload($off)->get('domain_sold')->isEmpty(), 'No sale without what it brought.');
    $this->assertTrue($this->reload($selling)->get('domain_sold')->isEmpty(), 'Still for sale: left to the marketplace sync.');

    // Once the amount is worked out, the sale of a domain off sale is
    // recorded; here by Drush.
    $this->setSales(['off.com' => [self::SOLD, 1616.3, NULL]]);
    $this->assertSame(0, $command->sales('market', ['domains' => 'Off.com', 'dry-run' => FALSE]));
    $this->assertStringContainsString('Filled in the sale of 1 domains.', $this->output->fetch());
    $off = $this->reload($off);
    $this->assertSame(self::SOLD, (int) $off->get('domain_sold')->value);
    $this->assertSame('market', $off->get('domain_sold_via')->target_id);
    $this->assertPrice('1616.3', $off);
  }

}

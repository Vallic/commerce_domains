<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains_owner\Controller\ExpensesController;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Expenses;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the portfolio's expenses by year, the renewals ahead and the page.
 */
#[CoversClass(Expenses::class)]
#[CoversClass(ExpensesController::class)]
#[CoversClass(Costs::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ExpensesTest extends OwnerKernelTestBase {

  /**
   * Returns a Unix time for a date, at noon UTC.
   */
  protected static function date(string $date): int {
    return (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   * Tests the totals by year, the renewals ahead, and what a domain cost.
   */
  public function testExpenses(): void {
    $date = self::date(...);
    $now = $date('2026-11-01');
    $this->setRenewalCosts([
      ['io', '50'],
    ]);
    // Held: bought in 2024, renewed on its expiry date in 2025 and 2026.
    $held = $this->createDomain('Held.com', 1000, [
      'domain_purchase_price' => new Price('100', 'USD'),
      'domain_purchase_date' => $date('2024-03-01'),
      'domain_renewal_cost' => new Price('10', 'USD'),
      'domain_expires' => $date('2027-03-01'),
    ]);
    // Sold within a year of its purchase: no renewal, received less 10%.
    $this->createDomain('Sold.com', 1000, [
      'domain_purchase_price' => new Price('50', 'USD'),
      'domain_purchase_date' => $date('2025-06-01'),
      'domain_sold' => $date('2026-02-01'),
      'domain_sale_price' => new Price('500', 'USD'),
      'domain_sale_commission' => '10',
    ]);
    // On the extension's default renewal cost, renewing in the next year.
    $this->createDomain('Default.io', 1000, [
      'domain_purchase_price' => new Price('30', 'USD'),
      'domain_purchase_date' => $date('2026-01-10'),
      'domain_expires' => $date('2027-01-10'),
    ]);
    // Left to expire: not among the renewals ahead.
    $this->createDomain('Leaving.com', 1000, [
      'domain_purchase_price' => new Price('20', 'USD'),
      'domain_purchase_date' => $date('2026-05-01'),
      'domain_renewal_cost' => new Price('10', 'USD'),
      'domain_expires' => $date('2026-12-01'),
      'domain_renewal' => DomainCosts::EXPIRE,
    ]);
    $this->createDomain('Unpriced.com');

    $expenses = $this->container->get(Expenses::class);
    $by_year = $expenses->byYear($now);
    $this->assertSame([2026, 2025, 2024], array_keys($by_year['years']));
    $this->assertSame(1, $by_year['unpriced']);
    $this->assertSame(0, $by_year['undated']);
    $y2026 = $by_year['years'][2026];
    $this->assertSame(2, $y2026['bought']);
    $this->assertEquals(['USD' => new Price('50', 'USD')], $y2026['purchases']);
    $this->assertSame(1, $y2026['renewals'], 'Held.com renewed on 2026-03-01.');
    $this->assertEquals(['USD' => new Price('10', 'USD')], $y2026['renewal_costs']);
    $this->assertSame(1, $y2026['sold']);
    $this->assertEquals(['USD' => new Price('450', 'USD')], $y2026['received']);
    // 450 received less 50 bought less 10 renewed.
    $this->assertEquals(['USD' => new Price('390', 'USD')], ExpensesController::net($y2026));
    $this->assertSame(1, $by_year['years'][2025]['renewals']);
    $this->assertEquals(['USD' => new Price('-60', 'USD')], ExpensesController::net($by_year['years'][2025]));

    $upcoming = $expenses->upcoming($now);
    $this->assertSame(2, $upcoming['count'], 'Held.com and Default.io renew; Leaving.com goes, Sold.com is gone.');
    $this->assertEquals(['USD' => new Price('60', 'USD')], $upcoming['costs']);

    $invested = $expenses->invested($held, $now);
    $this->assertSame(2, $invested['renewals']);
    $this->assertEquals(new Price('120', 'USD'), $invested['total']);
    $this->assertFalse($invested['default']);
    $default = $this->container->get('entity_type.manager')->getStorage('commerce_product')->loadByProperties(['domain' => 'default.io']);
    $default = reset($default);
    $this->assertInstanceOf(ProductInterface::class, $default);
    $this->assertTrue($expenses->invested($default, $now)['default']);

    // The page, behind the same permissions as the sales report.
    $this->setUpCurrentUser([], ['view domain portfolio']);
    $build = $this->container->get('class_resolver')->getInstanceFromDefinition(ExpensesController::class)->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('1 domain has no purchase price', $html);
    $this->assertStringContainsString('Held.com', $html);
    $this->assertStringNotContainsString('Sold.com', (string) $this->container->get('renderer')->renderInIsolation($build['held']), 'Only domains held.');
    $this->assertStringContainsString('(default)', $html);
  }

  /**
   * Tests that the renewal dates add up to the renewal count.
   */
  public function testRenewalDates(): void {
    $dates = Costs::renewalDates(self::date('2020-03-01'), self::date('2023-06-01'), self::date('2024-03-01'));
    $this->assertSame(['2021-03-01', '2022-03-01', '2023-03-01'], array_map(fn(int $time) => gmdate('Y-m-d', $time), $dates));
    $this->assertSame(3, Costs::renewals(self::date('2020-03-01'), self::date('2023-06-01'), self::date('2024-03-01')));
    $this->assertSame([], Costs::renewalDates(self::date('2023-06-01'), self::date('2020-03-01')));
  }

}

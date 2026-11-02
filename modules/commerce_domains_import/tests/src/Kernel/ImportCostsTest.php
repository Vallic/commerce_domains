<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests importing what domains cost and their sales, with Domain Owner.
 */
#[CoversClass(DomainImporter::class)]
#[CoversClass(ImportParser::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ImportCostsTest extends ImportKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_domains_import',
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_domains_owner',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_domains_renewal');
    $this->installEntitySchema('commerce_domains_renewal_cost');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installConfig(['system', 'commerce_order', 'commerce_domains_owner']);
    $this->container->get('module_handler')->loadInclude('commerce_domains_owner', 'install');
    commerce_domains_owner_install(FALSE);
    Provider::create(['id' => 'atom', 'label' => 'Atom', 'plugin' => 'atom'])->save();
  }

  /**
   * Returns a price field's value.
   */
  protected static function price(?ProductInterface $product, string $field): ?Price {
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem|null $item */
    $item = $product?->get($field)->first();
    return $item?->toPrice();
  }

  /**
   * Tests the cost and sale columns, and the rows they refuse.
   */
  public function testCostsAndSales(): void {
    $csv = "domain,price,purchase price,purchase date,renewal cost,sold,sold for,commission,sold via,sold lease months\n"
      . "held.com,1500,100,2024-03-01,10.50,,,,,\n"
      . "sold.com,,50 EUR,2025-01-10,,2026-06-21,420,7.38%,Atom,\n"
      . "leased.com,2000,,,,2026-02-01,3600,,atom,36\n"
      . "nosale.com,1000,,,,2026-02-01,,,,\n"
      . "badvia.com,1000,,,,2026-02-01,100,,Sedo,\n"
      . "badfee.com,1000,,,,2026-02-01,100,120,,\n";
    $report = $this->import(ImportParser::parseCsv($csv));
    $this->assertSame(['held.com', 'sold.com', 'leased.com'], $report->created);
    $this->assertSame([
      '5: nosale.com: A sale needs the date it sold (sold) and what it sold for (sold_for).',
      '6: badvia.com: No provider Sedo: give a provider ID or name.',
      '7: badfee.com: Commission 120 is not a percentage from 0 to 100.',
    ], self::errors($report));

    $held = $this->product('held.com');
    $this->assertEquals(new Price('100', 'USD'), self::price($held, 'domain_purchase_price'));
    $this->assertEquals(new Price('10.50', 'USD'), self::price($held, 'domain_renewal_cost'));
    $this->assertSame('2024-03-01', gmdate('Y-m-d', (int) $held->get('domain_purchase_date')->value));
    $this->assertTrue($held->get('domain_sold')->isEmpty());
    $this->assertTrue((bool) $this->variation('held.com')->get('in_stock')->value, 'Still on sale.');

    $sold = $this->product('sold.com');
    $this->assertEquals(new Price('50', 'EUR'), self::price($sold, 'domain_purchase_price'), 'A cell may name its currency.');
    $this->assertSame('2026-06-21', gmdate('Y-m-d', (int) $sold->get('domain_sold')->value));
    $this->assertEquals(new Price('420', 'USD'), self::price($sold, 'domain_sale_price'));
    $this->assertEquals(7.38, $sold->get('domain_sale_commission')->value);
    $this->assertSame('atom', $sold->get('domain_sold_via')->target_id);
    $variation = $this->variation('sold.com');
    $this->assertFalse((bool) $variation->get('in_stock')->value, 'Imported as sold: off sale.');
    $this->assertEquals(new Price('420', 'USD'), $variation->getPrice(), 'Without a price, what it sold for.');
    $this->assertSame(36, (int) $this->product('leased.com')->get('domain_lease_months')->value);

    // A sale can be added to a domain in the catalog, which goes off sale.
    $report = $this->import(ImportParser::parseCsv("domain,sold,sold for,sold via\nheld.com,2026-09-01,2500,atom\n"), ['existing' => 'update']);
    $this->assertSame(['held.com'], $report->updated);
    $this->assertEquals(new Price('2500', 'USD'), self::price($this->product('held.com'), 'domain_sale_price'));
    $this->assertFalse((bool) $this->variation('held.com')->get('in_stock')->value);
  }

}

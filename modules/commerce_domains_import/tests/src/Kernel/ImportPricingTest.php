<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_domains_pricing\PriceRule;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_price\Price;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests importing domains with Commerce Domains Pricing's rules.
 *
 * The importer asks PriceRules::price() for rows without a price, with the
 * name as written, so the rules can count its words.
 */
#[CoversClass(DomainImporter::class)]
#[CoversClass(PriceRules::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ImportPricingTest extends ImportKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_pricing'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['commerce_domains_pricing']);
    $rules = [
      new PriceRule(new Price('5000', 'USD'), atomPremium: TRUE),
      new PriceRule(new Price('900', 'EUR'), extensions: ['io']),
      new PriceRule(new Price('1999', 'USD'), extensions: ['com'], minWords: 2, maxWords: 2),
    ];
    $this->config(PriceRules::CONFIG)->set('rules', array_map(fn(PriceRule $rule) => $rule->toConfig(), $rules))->save();
  }

  /**
   * Tests that new domains without a price get the rules' price.
   */
  public function testRules(): void {
    $importer = $this->container->get(DomainImporter::class);
    $this->assertTrue($importer->hasPriceRules(), 'The container injects the pricing rules.');

    $csv = implode("\n", [
      'Domain Name,Selling Price,Atom Premium',
      'BookShop.com,,',
      'bookshop.net,,',
      'Example.io,,',
      'Premium.org,,yes',
      'Priced.com,1200,',
      'Lowercase.com,,',
    ]);
    $report = $this->import(ImportParser::parseCsv($csv), ['price' => '750']);
    $this->assertSame([], self::errors($report));
    $this->assertSame(['bookshop.com', 'bookshop.net', 'example.io', 'premium.org', 'priced.com', 'lowercase.com'], $report->created);

    $this->assertEquals(new Price('1999', 'USD'), $this->variation('bookshop.com')->getPrice(), 'Two words, as written.');
    $this->assertEquals(new Price('750', 'USD'), $this->variation('bookshop.net')->getPrice(), 'No rule: the default price.');
    $this->assertEquals(new Price('900', 'EUR'), $this->variation('example.io')->getPrice(), 'A rule in another currency.');
    $this->assertEquals(new Price('5000', 'USD'), $this->variation('premium.org')->getPrice(), 'The rules see the row\'s Atom premium flag.');
    $this->assertTrue((bool) $this->product('premium.org')->get('atom_premium')->value);
    $this->assertEquals(new Price('1200', 'USD'), $this->variation('priced.com')->getPrice(), 'The row\'s price wins.');
    $this->assertEquals(new Price('750', 'USD'), $this->variation('lowercase.com')->getPrice(), 'One word: the two-word rule does not match.');

    // Without a default price, rows no rule matches are refused.
    $report = $this->import(ImportParser::parseList("BookStore.com\nnothing.org"));
    $this->assertSame(['bookstore.com'], $report->created);
    $this->assertSame(['2: nothing.org: No price: the row has none, and no default price applies.'], self::errors($report));
    $this->assertEquals(new Price('1999', 'USD'), $this->variation('bookstore.com')->getPrice());

    // A dry run prices the same way, and saves nothing.
    $report = $this->import(ImportParser::parseList('BookCase.com'), ['dryRun' => TRUE]);
    $this->assertSame(['bookcase.com'], $report->created);
    $this->assertNull($this->product('bookcase.com'));
  }

  /**
   * Tests that the rules never replace a price already in the catalog.
   */
  public function testExistingDomains(): void {
    $this->createDomain('BookShelf.com', 300);
    $report = $this->import(ImportParser::parseList('BookShelf.com'), ['existing' => 'update', 'price' => '750']);
    $this->assertSame([], self::errors($report));
    $this->assertSame([], $report->updated, 'A row without a price changes nothing.');
    $this->assertEquals(new Price('300', 'USD'), $this->variation('bookshelf.com')->getPrice());

    // Saving the domain again keeps its price too.
    $this->variation('bookshelf.com')->save();
    $this->assertEquals(new Price('300', 'USD'), $this->variation('bookshelf.com')->getPrice());
  }

}

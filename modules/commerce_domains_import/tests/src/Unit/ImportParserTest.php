<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Unit;

use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_domains_import\ImportRow;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading lists, CSV files and cell values.
 */
#[CoversClass(ImportParser::class)]
#[CoversClass(ImportRow::class)]
#[Group('commerce_domains')]
class ImportParserTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Error messages are translatable.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Tests a pasted list: separators, prices, comments and blank lines.
   */
  public function testList(): void {
    $rows = ImportParser::parseList("\xEF\xBB\xBFExample.com 1500\r\nexample.net, 2,500 EUR\n\n# Not a domain\n  plain.org  \nsemi.io;99\ntabbed.com\t10");
    $this->assertSame([1, 2, 5, 6, 7], array_map(fn(ImportRow $row) => $row->line, $rows));
    $this->assertSame(['domain' => 'Example.com', 'price' => '1500'], $rows[0]->values);
    $this->assertSame(['domain' => 'example.net', 'price' => '2,500 EUR'], $rows[1]->values);
    $this->assertSame(['domain' => 'plain.org'], $rows[2]->values, 'A line without a price has none.');
    $this->assertSame(['domain' => 'plain.org', 'price' => ''], $rows[2]->raw);
    $this->assertSame('99', $rows[3]->value('price'));
    $this->assertSame('tabbed.com', $rows[4]->value('domain'));
    $this->assertNull($rows[2]->value('price'));
  }

  /**
   * Tests a CSV file: aliases, case, delimiters, quoting and line numbers.
   */
  public function testCsv(): void {
    $csv = "Domain Name;Selling Price;Score;CURRENCY;Expiration Date\n"
      . "One.com;\"1,500\";9.5;EUR;2027-03-01\n"
      . ";;;;\n"
      . "\"two.com\";200;;;\n"
      . "three.com;\"multi\nline\";1;;\n"
      . "four.com;-;;;N/A\n";
    $rows = ImportParser::parseCsv($csv);
    $this->assertCount(4, $rows, 'Empty rows are left out.');
    $this->assertSame([2, 4, 5, 7], array_map(fn(ImportRow $row) => $row->line, $rows), 'Rows are numbered by the line they start on.');
    $this->assertSame(['domain' => 'One.com', 'price' => '1,500', 'currency' => 'EUR', 'expires' => '2027-03-01'], $rows[0]->values);
    $raw = [
      'Domain Name' => 'One.com',
      'Selling Price' => '1,500',
      'Score' => '9.5',
      'CURRENCY' => 'EUR',
      'Expiration Date' => '2027-03-01',
    ];
    $this->assertSame($raw, $rows[0]->raw, 'The raw row keeps every column, as headed.');
    $this->assertSame("multi\nline", $rows[2]->value('price'));
    $this->assertNull($rows[3]->value('price'), 'A dash is empty.');
    $this->assertNull($rows[3]->value('expires'), 'N/A is empty.');

    // The earlier alias wins, a sep= line sets the delimiter.
    $rows = ImportParser::parseCsv("sep=,\nname,domain,price\nignored,picked.com,5\n");
    $this->assertSame('picked.com', $rows[0]->value('domain'));
    $this->assertSame(3, $rows[0]->line);

    // Spreadsheets on Windows write Windows-1252.
    $rows = ImportParser::parseCsv("domain,description\nexample.com,caf\xE9\n");
    $this->assertSame('café', $rows[0]->value('description'));
  }

  /**
   * Tests files the import can't read.
   */
  public function testBadCsv(): void {
    try {
      ImportParser::parseCsv("price,currency\n100,USD\n");
      $this->fail('A file without a domain column is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('No domain column', $e->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    ImportParser::parseCsv('');
  }

  /**
   * Tests reading amounts.
   */
  #[DataProvider('amounts')]
  public function testAmount(string $value, ?array $expected): void {
    if ($expected === NULL) {
      $this->expectException(\InvalidArgumentException::class);
    }
    $this->assertSame($expected, ImportParser::amount($value));
  }

  /**
   * Amounts and how they read; NULL for refused ones.
   */
  public static function amounts(): array {
    return [
      ['1500', ['1500', NULL]],
      ['1,500.50', ['1500.50', NULL]],
      ['$1,500', ['1500', NULL]],
      ['1500 eur', ['1500', 'EUR']],
      ['GBP 99', ['99', 'GBP']],
      ['1500,50', ['1500.50', NULL]],
      ['0', ['0', NULL]],
      ['.5', ['.5', NULL]],
      ['-5', NULL],
      ['about 5', NULL],
      ['1.500,00', NULL],
    ];
  }

  /**
   * Tests reading yes or no, and dates.
   */
  public function testFlagsAndDates(): void {
    foreach (['yes', 'Y', 'TRUE', '1', 'x'] as $value) {
      $this->assertTrue(ImportParser::flag($value), $value);
    }
    foreach (['no', 'N', 'false', '0', 'off'] as $value) {
      $this->assertFalse(ImportParser::flag($value), $value);
    }
    $this->assertSame(1803859200, ImportParser::date('2027-03-01'));
    $this->assertSame(1803859200, ImportParser::date('03/01/2027'));
    $this->assertSame(1803859200, ImportParser::date('20270301'));
    $this->assertSame(1803859200, ImportParser::date('1803859200'));
    foreach (['2027', 'soon'] as $value) {
      try {
        ImportParser::date($value);
        $this->fail("$value is not a date.");
      }
      catch (\InvalidArgumentException) {
        // Expected.
      }
    }
    $this->expectException(\InvalidArgumentException::class);
    ImportParser::flag('maybe');
  }

  /**
   * Tests matching headers.
   */
  public function testColumns(): void {
    $this->assertSame('tlds_taken', ImportParser::key(' TLDs Taken '));
    $columns = ImportParser::columns(['BIN Price', 'Domain', 'Min. Offer', 'Unknown']);
    $this->assertSame(['domain' => 1, 'price' => 0, 'minimum_offer' => 2], $columns);
  }

}

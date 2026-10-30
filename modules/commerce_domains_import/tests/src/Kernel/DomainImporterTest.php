<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\Event\DomainImportEvent;
use Drupal\commerce_domains_import\ImportOptions;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_domains_import\ImportReport;
use Drupal\commerce_domains_import\ImportRow;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests importing domains from lists, CSV files and registrar accounts.
 */
#[CoversClass(DomainImporter::class)]
#[CoversClass(DomainImportEvent::class)]
#[CoversClass(ImportReport::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainImporterTest extends ImportKernelTestBase {

  // An expiry date the test registrar reports.
  protected const EXPIRES = 1_820_000_000;

  /**
   * Tests a pasted list: prices, defaults, and the rows it refuses.
   */
  public function testList(): void {
    $rows = ImportParser::parseList(implode("\n", [
      'LearnPhpOnline.com 1500',
      'example.net, 2,500 EUR',
      'default.org',
      'free.com 0',
      'shop.example.org',
      'example',
      'LEARNPHPONLINE.COM 10',
      'negative.com -5',
      'xn--mnchen-3ya.de 300',
      'pounds.com 50 GBP',
    ]));
    $report = $this->import($rows, ['price' => '900', 'buyNow' => FALSE, 'published' => FALSE]);

    $this->assertSame(['learnphponline.com', 'example.net', 'default.org', 'free.com', 'xn--mnchen-3ya.de'], $report->created);
    $this->assertSame([
      '5: shop.example.org: Not a registrable domain name.',
      '6: example: Not a registrable domain name.',
      '7: LEARNPHPONLINE.COM: Repeats line 1.',
      '8: negative.com: Prices must be 0 or more, not -5.',
      '10: pounds.com: GBP is not an enabled currency.',
    ], self::errors($report));

    $product = $this->product('learnphponline.com');
    $this->assertSame('LearnPhpOnline.com', $product->getTitle(), 'The title keeps the name as given.');
    $this->assertFalse($product->isPublished());
    $this->assertSame([$this->store->id()], $product->getStoreIds());
    $variation = $this->variation('learnphponline.com');
    $this->assertEquals(new Price('1500', 'USD'), $variation->getPrice());
    $this->assertSame('learnphponline.com', $variation->getSku());
    $this->assertFalse((bool) $variation->get('sale_buy_now')->value);
    $this->assertTrue((bool) $variation->get('sale_offers')->value);
    $this->assertTrue((bool) $variation->get('in_stock')->value);
    $this->assertTrue($variation->isPublished());

    $this->assertEquals(new Price('2500', 'EUR'), $this->variation('example.net')->getPrice());
    $this->assertEquals(new Price('900', 'USD'), $this->variation('default.org')->getPrice(), 'Rows without a price get the default.');
    $this->assertEquals(new Price('0', 'USD'), $this->variation('free.com')->getPrice());
    $this->assertSame('münchen.de', $this->product('xn--mnchen-3ya.de')->getTitle(), 'IDNs get their Unicode title.');
  }

  /**
   * Tests a CSV file, and sites mapping their own columns.
   */
  public function testCsv(): void {
    Provider::create(['id' => 'dynadot', 'label' => 'Dynadot'])->save();
    Provider::create(['id' => 'afternic', 'label' => 'Afternic', 'plugin' => 'afternic'])->save();
    $csv = "Domain Name,Selling Price,Currency,Buy Now,Offers,Min Offer,Description,Registrar,Expires,Premium,Score,Internal\n"
      . "One.com,\"5,000\",EUR,no,yes,2000,The first,Dynadot,2027-03-01,yes,9.5,\n"
      . "two.com,100,,,,,,,,,1,\n"
      . "three.com,100,,,,,,Afternic,,,,\n"
      . "four.com,100,,maybe,,,,,,,,\n"
      . "refused.com,100,,,,,,,,,,\n"
      . "five.com,100,,,,,,DYNADOT,,,,yes\n";

    $events = [];
    $this->container->get('event_dispatcher')->addListener(DomainImportEvent::NAME, function (DomainImportEvent $event) use (&$events) {
      $events[$event->product->getTitle()] = [
        'line' => $event->line,
        'new' => $event->isNew,
        'unsaved' => $event->product->isNew() && $event->variation->isNew(),
        'score' => $event->value('score'),
        'raw' => $event->row,
      ];
      // A site's own column: the score goes in the SKU, as a stand-in.
      if ($score = $event->value('SCORE')) {
        $event->variation->setSku($event->variation->getSku() . '-' . $score);
      }
      if ($event->product->getTitle() === 'refused.com') {
        $event->setError('Refused by the site.');
      }
    });
    $report = $this->import(ImportParser::parseCsv($csv), ['source' => ImportOptions::SOURCE_CSV]);

    $this->assertSame(['one.com', 'two.com', 'five.com'], $report->created);
    $this->assertSame([
      '4: three.com: No registrar Afternic: add it at Commerce → Configuration → Domains → Domain providers, or leave the cell empty.',
      '5: four.com: Not yes or no: maybe.',
      '6: refused.com: Refused by the site.',
    ], self::errors($report));
    $this->assertNull($this->product('refused.com'), 'A refused row is not saved.');

    // The event gets the raw row before anything is saved.
    $this->assertSame(['line' => 2, 'new' => TRUE, 'unsaved' => TRUE, 'score' => '9.5'], array_diff_key($events['One.com'], ['raw' => TRUE]));
    $this->assertSame('5,000', $events['One.com']['raw']['Selling Price']);
    $this->assertSame('9.5', $events['One.com']['raw']['Score']);
    $this->assertSame('one.com-9.5', $this->variation('one.com')->getSku(), 'Subscribers change the domain before it is saved.');

    $product = $this->product('one.com');
    $this->assertSame('dynadot', $product->get('domain_registrar')->target_id, 'Registrars match by name.');
    $this->assertSame(1803859200, (int) $product->get('domain_expires')->value);
    $this->assertTrue((bool) $product->get('atom_premium')->value);
    $this->assertSame('The first', $product->get('body')->value);
    $this->assertSame('plain_text', $product->get('body')->format);
    $variation = $this->variation('one.com');
    $this->assertEquals(new Price('5000', 'EUR'), $variation->getPrice());
    $this->assertFalse((bool) $variation->get('sale_buy_now')->value);
    $this->assertTrue((bool) $variation->get('sale_offers')->value);
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $minimum */
    $minimum = $variation->get('sale_minimum_offer')->first();
    $this->assertEquals(new Price('2000', 'EUR'), $minimum->toPrice(), 'The minimum offer is in the price currency.');

    $this->assertTrue((bool) $this->variation('two.com')->get('sale_buy_now')->value, 'New domains take the default terms.');
    $this->assertTrue($this->product('two.com')->get('domain_registrar')->isEmpty());
    $this->assertSame('dynadot', $this->product('five.com')->get('domain_registrar')->target_id);
    $this->assertTrue((bool) $this->product('five.com')->get('domain_internal')->value);
    $this->assertFalse($this->product('five.com')->isPublished(), 'Internal domains stay unpublished.');
    $this->assertFalse((bool) $this->product('two.com')->get('domain_internal')->value);

    // The import can make all its new domains internal.
    $report = $this->import(ImportParser::parseList("six.com 100\n"), ['internal' => TRUE]);
    $this->assertSame(['six.com'], $report->created);
    $this->assertTrue((bool) $this->product('six.com')->get('domain_internal')->value);
    $this->assertFalse($this->product('six.com')->isPublished());
  }

  /**
   * Tests importing the domains a registrar account holds.
   */
  public function testRegistrarAccount(): void {
    $provider = Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar']);
    $provider->save();
    $this->container->get('state')->set('commerce_domains_test.registrations', [
      'held.com' => self::EXPIRES,
      'listed.com' => self::EXPIRES,
      'other.net' => NULL,
    ]);
    $this->createDomain('Listed.com');

    $importer = $this->container->get(DomainImporter::class);
    $plan = $importer->readAccount($provider);
    $this->assertSame(['held.com', 'other.net'], $plan->unknown, 'The account domains the catalog lacks.');

    $expires = array_map(fn($registration) => $registration->expires, $plan->registrations);
    $rows = $importer->accountRows(ImportParser::parseList("held.com 700\nother.net\nnot-there.com\nlisted.com"), $provider, $expires);
    $report = $this->import($rows, ['source' => ImportOptions::SOURCE_REGISTRAR, 'price' => '250']);

    $this->assertSame(['held.com', 'other.net'], $report->created);
    $this->assertSame(['listed.com' => 'in the catalog'], $report->skipped);
    $this->assertSame(['3: not-there.com: Not in the Reg account.'], self::errors($report));
    $held = $this->product('held.com');
    $this->assertSame('reg', $held->get('domain_registrar')->target_id);
    $this->assertSame(self::EXPIRES, (int) $held->get('domain_expires')->value);
    $this->assertTrue((bool) $held->get('domain_verified')->value, 'The account shows the domain is the seller\'s.');
    $this->assertEquals(new Price('700', 'USD'), $this->variation('held.com')->getPrice());
    $this->assertSame('reg', $this->product('other.net')->get('domain_registrar')->target_id);
    $this->assertTrue($this->product('other.net')->get('domain_expires')->isEmpty());
    $this->assertEquals(new Price('250', 'USD'), $this->variation('other.net')->getPrice());

    $this->assertSame([], $importer->readAccount($provider)->unknown, 'Nothing is left to import.');
  }

  /**
   * Tests skipping or updating domains already in the catalog.
   */
  public function testExisting(): void {
    $this->createDomain('Taken.com', 1000, ['status' => FALSE], ['sale_buy_now' => TRUE, 'sale_offers' => TRUE]);
    $this->createDomain('Same.com', 1000, [], ['sale_offers' => TRUE]);
    $this->createDomain('Euro.com', 1000, [], ['price' => new Price('500', 'EUR')]);
    $csv = "domain,price,offers,description\nTaken.com,1200,no,Now cheaper\nsame.com,1000,yes,\neuro.com,600,,\nnew.com,10,,\n";

    $report = $this->import(ImportParser::parseCsv($csv));
    $this->assertSame(['new.com'], $report->created);
    $this->assertSame(['taken.com' => 'in the catalog', 'same.com' => 'in the catalog', 'euro.com' => 'in the catalog'], $report->skipped);
    $this->assertEquals(new Price('1000', 'USD'), $this->variation('taken.com')->getPrice());

    $report = $this->import(ImportParser::parseCsv($csv), ['existing' => ImportOptions::UPDATE, 'buyNow' => FALSE]);
    $this->assertSame([], $report->created);
    $this->assertSame(['taken.com', 'euro.com'], $report->updated);
    $this->assertSame(['same.com' => 'no changes', 'new.com' => 'no changes'], $report->skipped);

    $variation = $this->variation('taken.com');
    $this->assertEquals(new Price('1200', 'USD'), $variation->getPrice());
    $this->assertFalse((bool) $variation->get('sale_offers')->value);
    $this->assertTrue((bool) $variation->get('sale_buy_now')->value, 'What the row leaves out stays as it is.');
    $this->assertFalse($this->product('taken.com')->isPublished(), 'Updates keep the published status.');
    $this->assertSame('Now cheaper', $this->product('taken.com')->get('body')->value);
    $this->assertEquals(new Price('600', 'EUR'), $this->variation('euro.com')->getPrice(), 'A price without a currency stays in the domain\'s.');
    $this->assertCount(1, $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['domain' => 'taken.com']), 'No second product.');
  }

  /**
   * Tests that a dry run reports the same and saves nothing.
   */
  public function testDryRun(): void {
    $this->createDomain('Taken.com', 1000);
    $csv = "domain,price\ntaken.com,1200\nnew.com,10\nbad\n";
    $saved = 0;
    $this->container->get('event_dispatcher')->addListener('commerce_product.commerce_product.presave', function () use (&$saved) {
      $saved++;
    });
    $events = [];
    $this->container->get('event_dispatcher')->addListener(DomainImportEvent::NAME, function (DomainImportEvent $event) use (&$events) {
      $events[] = $event->options->dryRun;
    });

    $report = $this->import(ImportParser::parseCsv($csv), ['existing' => ImportOptions::UPDATE, 'dryRun' => TRUE]);
    $this->assertTrue($report->dryRun);
    $this->assertSame(['new.com'], $report->created);
    $this->assertSame(['taken.com'], $report->updated);
    $this->assertSame(['4: bad: Not a registrable domain name.'], self::errors($report));
    $this->assertSame([TRUE, TRUE], $events, 'Subscribers see dry runs too, and know them.');
    $this->assertSame(0, $saved, 'Nothing is saved.');
    $this->assertNull($this->product('new.com'));
    $this->assertEquals(new Price('1000', 'USD'), $this->variation('taken.com')->getPrice());

    // The same rows, for real, do what the dry run said.
    $report = $this->import(ImportParser::parseCsv($csv), ['existing' => ImportOptions::UPDATE]);
    $this->assertSame(['new.com'], $report->created);
    $this->assertSame(['taken.com'], $report->updated);
    $this->assertEquals(new Price('1200', 'USD'), $this->variation('taken.com')->getPrice());
  }

  /**
   * Tests the price of rows without one when pricing rules are absent.
   */
  public function testPriceFallback(): void {
    $importer = $this->container->get(DomainImporter::class);
    $this->assertFalse($importer->hasPriceRules(), 'Commerce Domains Pricing is not installed.');

    $rows = ImportParser::parseList("priced.com 10\nunpriced.com");
    $report = $this->import($rows, ['price' => '75', 'currency' => 'EUR']);
    $this->assertSame(['priced.com', 'unpriced.com'], $report->created);
    $this->assertEquals(new Price('10', 'EUR'), $this->variation('priced.com')->getPrice(), 'Prices without a currency take the default currency.');
    $this->assertEquals(new Price('75', 'EUR'), $this->variation('unpriced.com')->getPrice());

    // Without a default price, a row without one can't be imported.
    $report = $this->import(ImportParser::parseList('other.com'));
    $this->assertSame(['1: other.com: No price: the row has none, and no default price applies.'], self::errors($report));
    $this->assertNull($this->product('other.com'));
  }

  /**
   * Tests pricing rules, when Commerce Domains Pricing provides them.
   */
  public function testPriceRules(): void {
    if (!class_exists(PriceRules::class) || (new \ReflectionClass(PriceRules::class))->isFinal()) {
      $this->markTestSkipped('Commerce Domains Pricing is not available to mock.');
    }
    $rules = $this->createMock(PriceRules::class);
    $rules->method('price')->willReturnCallback(fn(string $domain, bool $premium = FALSE) => match ($domain) {
      'ruled.com' => new Price('3000', 'USD'),
      'premium.com' => $premium ? new Price('9000', 'USD') : NULL,
      default => NULL,
    });
    $importer = new DomainImporter(
      $this->entityTypeManager,
      $this->container->get('Drupal\commerce_domains\Catalog'),
      $this->container->get('Drupal\commerce_domains\DomainNameParser'),
      $this->container->get('Drupal\commerce_domains\Availability'),
      $this->container->get('Drupal\commerce_domains\RegistrarSync'),
      $this->container->get('Drupal\commerce_domains_import\RegistrarLookup'),
      $this->container->get('event_dispatcher'),
      $this->container->get('database'),
      $this->container->get('logger.channel.commerce_domains_import'),
      $rules,
    );
    $this->assertTrue($importer->hasPriceRules());
    $report = new ImportReport();
    $csv = "domain,price,premium\nruled.com,,\npremium.com,,yes\nplain.com,,\nown.com,5,\n";
    $importer->import(ImportParser::parseCsv($csv), $this->options(['price' => '100']), $report);
    $this->assertSame(['ruled.com', 'premium.com', 'plain.com', 'own.com'], $report->created);
    $this->assertEquals(new Price('3000', 'USD'), $this->variation('ruled.com')->getPrice());
    $this->assertEquals(new Price('9000', 'USD'), $this->variation('premium.com')->getPrice(), 'Rules know Atom premium names.');
    $this->assertEquals(new Price('100', 'USD'), $this->variation('plain.com')->getPrice(), 'No rule: the default price.');
    $this->assertEquals(new Price('5', 'USD'), $this->variation('own.com')->getPrice(), 'The row\'s own price wins.');
  }

  /**
   * Tests checking the choices.
   */
  public function testOptions(): void {
    $importer = $this->container->get(DomainImporter::class);
    $this->assertSame([], $importer->validateOptions($this->options(['price' => '0'])));
    ProductType::create(['id' => 'plain', 'label' => 'Plain', 'variationTypes' => ['default']])->save();
    $errors = $importer->validateOptions(new ImportOptions(productType: 'plain', store: 99, currency: 'XYZ', price: '-1', existing: 'merge'));
    $this->assertSame(['product_type', 'store', 'currency', 'price', 'existing'], array_keys($errors));
  }

  /**
   * Tests that a failing site mapping only fails its row.
   */
  public function testFailingSubscriber(): void {
    $this->container->get('event_dispatcher')->addListener(DomainImportEvent::NAME, function (DomainImportEvent $event) {
      if ($event->product->getTitle() === 'broken.com') {
        throw new \RuntimeException('Lookup timed out.');
      }
    });
    $report = $this->import(ImportParser::parseList("broken.com 10\nfine.com 10"));
    $this->assertSame(['fine.com'], $report->created);
    $this->assertSame(["1: broken.com: The site's own mapping failed: Lookup timed out."], self::errors($report));
    $this->assertNull($this->product('broken.com'));
  }

  /**
   * Tests that rows are counted once across chunks.
   */
  public function testChunks(): void {
    $rows = [];
    for ($i = 1; $i <= 60; $i++) {
      $rows[] = new ImportRow($i, [], ['domain' => "name$i.com", 'price' => '10']);
    }
    $report = new ImportReport();
    $importer = $this->container->get(DomainImporter::class);
    $importer->import(array_slice($rows, 0, 30), $this->options(), $report);
    $importer->import(array_merge(array_slice($rows, 30), [new ImportRow(61, [], ['domain' => 'NAME1.com'])]), $this->options(), $report);
    $this->assertCount(60, $report->created);
    $this->assertSame(['61: NAME1.com: Repeats line 1.'], self::errors($report));
    $this->assertSame(61, $report->processed());
  }

}

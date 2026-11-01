<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains_owner\Drush\Commands\SalesImportCommands;
use Drupal\commerce_domains_owner\Form\SalesImportForm;
use Drupal\commerce_domains_owner\SalesImporter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Form\FormState;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests importing domain sales from a CSV file.
 */
#[CoversClass(SalesImporter::class)]
#[CoversClass(SalesImportForm::class)]
#[CoversClass(SalesImportCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SalesImportTest extends OwnerKernelTestBase {

  use DrushCommandTrait;

  /**
   * The importer.
   *
   * @var \Drupal\commerce_domains_owner\SalesImporter
   */
  protected SalesImporter $importer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->importer = $this->container->get(SalesImporter::class);
    $this->createProvider('atom', 'test_marketplace');
  }

  /**
   * Tests the readers of cells.
   */
  public function testCells(): void {
    $this->assertSame([1500.0, NULL], SalesImporter::amount('1500'));
    $this->assertSame([1500.0, 'USD'], SalesImporter::amount('$1,500.00 USD'));
    $this->assertSame([1500.5, 'EUR'], SalesImporter::amount('EUR 1.500,50'));
    $this->assertSame([12.5, NULL], SalesImporter::amount('12,5'));
    $this->assertSame([2500.0, NULL], SalesImporter::amount('2,500'));
    $this->assertSame(15.0, SalesImporter::percent('15%'));
    $this->assertSame(12.5, SalesImporter::percent('12,5'));
    $this->assertSame(SalesImporter::date('2026-10-30'), SalesImporter::date('30.10.2026'));
    $this->assertSame(SalesImporter::date('2026-10-30'), SalesImporter::date('10/30/2026'));
    foreach ([
      fn() => SalesImporter::amount('soon'),
      fn() => SalesImporter::percent('120'),
      fn() => SalesImporter::date('31.02.2026'),
      fn() => SalesImporter::parse("title;price\nfoo;1"),
    ] as $read) {
      try {
        $read();
        $this->fail('Refused.');
      }
      catch (\InvalidArgumentException) {
      }
    }
  }

  /**
   * Tests new sales, filling in a sale, and the rows left out.
   */
  public function testImport(): void {
    $new = $this->createDomain('New.com', 3000);
    $sold = $this->createDomain('Sold.com', 1000, [
      'domain_sold' => SalesImporter::date('2026-09-01'),
      'domain_sale_price' => new Price('4800', 'USD'),
      'domain_sold_via' => 'atom',
    ]);
    $site = $this->createDomain('Site.com', 1000, [
      'domain_sold' => SalesImporter::date('2026-08-01'),
      'domain_sale_price' => new Price('900', 'USD'),
      'domain_sale_order' => 7,
    ]);
    $this->createDomain('Unpriced.com');
    $csv = "sep=;\nDomain;Sold;Price;Commission;Received;Sold via;Lease months\n"
      . "new.com;2026-10-30;2500 USD;20;;Atom;\n"
      . "sold.com;;;;3360;;6\n"
      . "site.com;;;15;;;\n"
      . "unpriced.com;2026-10-01;;;;;\n"
      . "missing.com;2026-10-01;100;;;;\n"
      . "new.com;2026-10-30;2600;;;;\n"
      . "\n";
    $rows = SalesImporter::parse($csv);
    $this->assertSame([3, 4, 5, 6, 7, 8], array_keys($rows), 'Rows by the line they are on, after the sep line.');
    $plan = $this->importer->plan($rows);

    $this->assertNull($plan[3]['problem']);
    $this->assertTrue($plan[3]['new']);
    $this->assertSame(['domain_sold', 'domain_sale_price', 'domain_sale_commission', 'domain_sold_via'], array_keys($plan[3]['changes']));
    $this->assertSame(['domain_sale_commission', 'domain_lease_months'], array_keys($plan[4]['changes']));
    $this->assertSame('30', $plan[4]['changes']['domain_sale_commission'], '3360 received of 4800.');
    $this->assertStringContainsString('order 7', $plan[5]['problem']);
    $this->assertStringContainsString('needs the date it sold and the price', $plan[6]['problem']);
    $this->assertSame('Not in the catalog.', $plan[7]['problem']);
    $this->assertSame('Already on line 3.', $plan[8]['problem']);
    $this->assertContains('Commission: 20%', $this->importer->describe($plan[3]['changes']));

    $this->assertSame(2, $this->importer->apply($plan));
    $new = Product::load($new->id());
    $this->assertEquals(2500, $new->get('domain_sale_price')->number);
    $this->assertSame('atom', $new->get('domain_sold_via')->target_id);
    $this->assertEquals(20, $new->get('domain_sale_commission')->value);
    $this->assertFalse((bool) $new->getVariations()[0]->get('in_stock')->value, 'A new sale goes off sale.');
    $sold = Product::load($sold->id());
    $this->assertEquals(30, $sold->get('domain_sale_commission')->value);
    $this->assertSame(6, (int) $sold->get('domain_lease_months')->value);
    $this->assertEquals(4800, $sold->get('domain_sale_price')->number, 'Empty cells leave the rest.');
    $this->assertEquals(900, Product::load($site->id())->get('domain_sale_price')->number);

    // Again, nothing changes.
    $plan = $this->importer->plan(SalesImporter::parse("domain,commission\nsold.com,30\n"));
    $this->assertSame([], $plan[2]['changes']);
  }

  /**
   * Tests the form's preview and import, and the Drush command.
   */
  public function testFormAndDrush(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $product = $this->createDomain('Form.com', 1000, [
      'domain_sold' => SalesImporter::date('2026-09-01'),
      'domain_sale_price' => new Price('1000', 'USD'),
    ]);
    $form_builder = $this->container->get('form_builder');
    $form_state = (new FormState())->setValues(['text' => "domain,commission\nform.com,12.5", 'op' => 'Preview']);
    $form_builder->submitForm(SalesImportForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $rows = $form_state->get('rows');
    $this->assertIsArray($rows);
    $this->assertSame(0, (int) Product::load($product->id())->get('domain_sale_commission')->value, 'Only a preview.');

    // The preview offers the import, which records the rows.
    $form_state = new FormState();
    $form_state->set('rows', $rows);
    $form = $form_builder->buildForm(SalesImportForm::class, $form_state);
    $this->assertSame('Import 1 sale', (string) $form['actions']['import']['#value']);
    $this->assertStringContainsString('Commission: 12.5%', (string) $this->container->get('renderer')->renderInIsolation($form['preview']));
    $form_state = (new FormState())->setValues(['op' => 'Import 1 sale']);
    $form_state->set('rows', $rows);
    $form_builder->submitForm(SalesImportForm::class, $form_state);
    $this->assertEquals(12.5, Product::load($product->id())->get('domain_sale_commission')->value);

    $form_state = (new FormState())->setValues(['text' => '', 'op' => 'Preview']);
    $form_builder->submitForm(SalesImportForm::class, $form_state);
    $this->assertNotEmpty($form_state->getErrors());

    $this->requireDrush();
    $file = $this->container->get('file_system')->getTempDirectory() . '/sales.csv';
    file_put_contents($file, "domain,commission\nform.com,15\nnowhere.com,10\n");
    $command = new SalesImportCommands($this->importer);
    $this->prepareCommand($command);
    $command->import($file, ['dry-run' => TRUE]);
    $output = $this->output->fetch();
    $this->assertMatchesRegularExpression('/form\.com\s+Commission: 15%/', $output);
    $this->assertStringContainsString('Left out: Not in the catalog.', $output);
    $this->assertEquals(12.5, Product::load($product->id())->get('domain_sale_commission')->value, 'A dry run changes nothing.');
    $command->import($file);
    $this->assertEquals(15, Product::load($product->id())->get('domain_sale_commission')->value);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_marketplace\CsvExport;
use Drupal\commerce_domains_marketplace\Drush\Commands\CsvExportCommands;
use Drupal\commerce_domains_marketplace\Form\CsvExportForm;
use Drupal\commerce_domains_marketplace\Hook\MarketplaceHooks;
use Drupal\Core\Form\FormState;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the CSV files for marketplace upload pages.
 */
#[CoversClass(CsvExport::class)]
#[CoversClass(CsvExportForm::class)]
#[CoversClass(CsvExportCommands::class)]
#[CoversClass(MarketplaceHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class CsvExportTest extends MarketplaceKernelTestBase {

  use DrushCommandTrait;
  use UserCreationTrait;

  /**
   * The CSV the DaaZ provider should write.
   */
  protected const DAAZ_CSV = "Domain,Price,\"Min. Offer\",Currency\nCheap.com,1050,525,USD\nBig.com,21000,5250,USD\n";

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createDomain('Cheap.com', 1000);
    $this->createDomain('Big.com', 20000);
    $this->createDomain('Sold.com', 3000, [], ['in_stock' => FALSE]);
    Provider::create(['id' => 'daaz', 'label' => 'DaaZ', 'plugin' => 'daaz'])->save();
    Provider::create(['id' => 'dynadot', 'label' => 'Dynadot', 'plugin' => 'dynadot'])->save();
    $this->createProvider('test');
  }

  /**
   * Tests the rows: domains for sale only, priced for the provider.
   */
  public function testRows(): void {
    $export = $this->container->get(CsvExport::class);
    $daaz = Provider::load('daaz');
    $rows = $export->rows($daaz);
    $this->assertSame(['listings'], array_keys($rows));
    $this->assertSame(self::DAAZ_CSV, CsvExport::encode($rows['listings']));
    $this->assertSame('daaz.csv', CsvExport::filename($daaz, 'listings'));

    // Dynadot writes two files from one read of the catalog: none of these
    // domains is registered there.
    $dynadot = Provider::load('dynadot');
    $rows = $export->rows($dynadot);
    $this->assertCount(1, $rows['listings']);
    $this->assertCount(3, $rows['external']);
    $this->assertSame(['external'], array_keys($export->rows($dynadot, 'external')));
    $this->assertSame('dynadot.csv', CsvExport::filename($dynadot, 'listings'));
    $this->assertSame('dynadot-external.csv', CsvExport::filename($dynadot, 'external'));

    try {
      $export->rows($daaz, 'external');
      $this->fail('A file the provider does not write should fail.');
    }
    catch (ProviderException $e) {
      $this->assertSame('DaaZ has no CSV file external.', $e->getMessage());
    }
    $this->expectExceptionMessage('Test has no CSV export.');
    $export->rows(Provider::load('test'));
  }

  /**
   * Tests the export page and its download.
   */
  public function testForm(): void {
    $this->assertTrue(CsvExportForm::access(Provider::load('daaz'))->isAllowed());
    $this->assertFalse(CsvExportForm::access(Provider::load('test'))->isAllowed());

    $form_builder = $this->container->get('form_builder');
    $form = $form_builder->getForm(CsvExportForm::class, Provider::load('daaz'));
    $this->assertSame('2 domains.', (string) $form['listings']['preview']['#caption']);
    $this->assertSame(['Cheap.com', 1050, 525, 'USD'], $form['listings']['preview']['#rows'][0]);
    $this->assertSame('Download daaz.csv', (string) $form['listings']['download']['#value']);

    // An empty file has nothing to download.
    $form = $form_builder->getForm(CsvExportForm::class, Provider::load('dynadot'));
    $this->assertSame('Domains at Dynadot', (string) $form['listings']['#title']);
    $this->assertFalse($form['listings']['download']['#access']);
    $this->assertTrue($form['external']['download']['#access']);

    $form_state = (new FormState())
      ->addBuildInfo('args', [Provider::load('daaz')])
      ->setValues(['download_listings' => 'Download daaz.csv']);
    $form_builder->submitForm(CsvExportForm::class, $form_state);
    $response = $form_state->getResponse();
    $this->assertInstanceOf(Response::class, $response);
    $this->assertSame(self::DAAZ_CSV, $response->getContent());
    $this->assertSame('attachment; filename="daaz.csv"', $response->headers->get('Content-Disposition'));
    $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
  }

  /**
   * Tests the provider form and the operation link.
   */
  public function testProviderUi(): void {
    // A provider that only writes files gets prices, but no sync settings.
    $form = $this->container->get('entity.form_builder')->getForm(Provider::load('daaz'), 'edit');
    $this->assertSame('Marketplace prices', (string) $form['marketplace']['#title']);
    $this->assertFalse($form['marketplace']['auto_sync']['#access']);
    $this->assertSame('Marketplace sync', (string) $this->container->get('entity.form_builder')->getForm(Provider::load('test'), 'edit')['marketplace']['#title']);

    $form_object = $this->entityTypeManager->getFormObject('commerce_domains_provider', 'edit')->setEntity(Provider::load('daaz'));
    $form_state = (new FormState())->setValues([
      'label' => 'DaaZ',
      'status' => 1,
      'configuration' => ['price_factor' => '1.1', 'premium_price_factor' => '1.2'],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertNull($this->config('commerce_domains.provider.daaz')->get('third_party_settings.commerce_domains_marketplace'), 'No sync settings are stored.');
    $this->assertSame(1.1, Provider::load('daaz')->getPlugin()->getConfiguration()['price_factor']);

    $list_builder = $this->entityTypeManager->getListBuilder('commerce_domains_provider');
    $this->assertArrayNotHasKey('csv_export', $list_builder->getOperations(Provider::load('daaz')), 'Without access, no link.');
    $this->setUpCurrentUser([], ['administer commerce_domains providers']);
    $operations = $list_builder->getOperations(Provider::load('daaz'));
    $this->assertSame('/admin/commerce/domains/settings/providers/daaz/csv', $operations['csv_export']['url']->toString());
    $this->assertArrayNotHasKey('csv_export', $list_builder->getOperations(Provider::load('test')));
  }

  /**
   * Tests the Drush command.
   */
  public function testCommand(): void {
    $this->requireDrush();
    $run = function (string $provider, ?string $file = NULL): int {
      $command = new CsvExportCommands($this->entityTypeManager, $this->container->get(CsvExport::class));
      $this->prepareCommand($command);
      return $command->export($provider, ['file' => $file]);
    };

    $this->assertSame(DrushCommands::EXIT_SUCCESS, $run('daaz'));
    $this->assertSame(self::DAAZ_CSV, $this->output->fetch());
    $this->assertSame(DrushCommands::EXIT_SUCCESS, $run('dynadot', 'external'));
    $this->assertStringStartsWith("Domain,Currency,\"Buy Now Price\"", $this->output->fetch());

    $this->assertSame(DrushCommands::EXIT_FAILURE, $run('test'));
    $this->assertSame(['No provider test with a CSV export.'], $this->messages('error'));
    $this->assertSame(DrushCommands::EXIT_FAILURE, $run('daaz', 'external'));
    $this->assertSame(['DaaZ has no file external; choose one of: listings.'], $this->messages('error'));
  }

}

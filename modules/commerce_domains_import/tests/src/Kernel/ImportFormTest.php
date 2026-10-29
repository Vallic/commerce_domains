<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_import\Form\ImportForm;
use Drupal\commerce_domains_import\Hook\ImportHooks;
use Drupal\commerce_domains_import\ImportBatch;
use Drupal\commerce_domains_import\ImportOptions;
use Drupal\commerce_domains_import\ImportReport;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests the import form, its batch and its report.
 */
#[CoversClass(ImportForm::class)]
#[CoversClass(ImportBatch::class)]
#[CoversClass(ImportHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class ImportFormTest extends ImportKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], ['import domains']);
  }

  /**
   * Returns the form's values for an import with the usual choices.
   *
   * Programmed submissions leave a checkbox unticked with NULL; any other
   * value ticks it.
   */
  protected function values(array $values): array {
    return $values + [
      'product_type' => 'default',
      'store' => $this->store->id(),
      'price' => ['number' => '', 'currency_code' => 'USD'],
      'buy_now' => 1,
      'offers' => 1,
      'published' => 1,
      'existing' => ImportOptions::SKIP,
      'lookup' => NULL,
      'dry_run' => NULL,
      'op' => 'Import',
    ];
  }

  /**
   * Submits the form; the batch runs at once, as for any programmed form.
   */
  protected function submit(FormState $form_state): void {
    $this->container->get('form_builder')->submitForm(ImportForm::class, $form_state);
  }

  /**
   * Returns the report the batch kept for the import page.
   */
  protected function lastReport(): ?ImportReport {
    $stored = $this->container->get('tempstore.private')->get(ImportBatch::STORE)->get('report');
    return $stored['report'] ?? NULL;
  }

  /**
   * Returns the comparison part of a Registrations tab, as altered.
   */
  protected function registrationsForm(FormStateInterface $form_state): array {
    $form = ['plan' => []];
    $this->container->get(ImportHooks::class)->registrationsFormAlter($form, $form_state);
    return $form;
  }

  /**
   * Tests the choices the form offers.
   */
  public function testBuild(): void {
    ProductType::create(['id' => 'plain', 'label' => 'Plain', 'variationTypes' => ['default']])->save();
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar'])->save();
    Provider::create(['id' => 'off', 'label' => 'Off', 'plugin' => 'test_registrar', 'status' => FALSE])->save();
    Provider::create(['id' => 'hover', 'label' => 'Hover'])->save();

    $form = $this->container->get('form_builder')->getForm(ImportForm::class);
    $this->assertSame(['default' => 'Default'], $form['defaults']['product_type']['#options'], 'Only domain product types.');
    $this->assertFalse($form['defaults']['product_type']['#access'], 'One type needs no choice.');
    $this->assertSame(['reg' => 'Reg'], $form['account']['provider']['#options'], 'Enabled registrars with an API.');
    $this->assertSame(ImportOptions::SOURCE_LIST, $form['source']['#default_value']);
    $this->assertArrayNotHasKey('names', $form['account'], 'The account is read only when asked.');

    // The Registrations tab links here for one provider.
    $this->container->get('request_stack')->getCurrentRequest()->query->set('provider', 'reg');
    $form = $this->container->get('form_builder')->getForm(ImportForm::class);
    $this->assertSame(ImportOptions::SOURCE_REGISTRAR, $form['source']['#default_value']);
    $this->assertSame('reg', $form['account']['provider']['#default_value']);
  }

  /**
   * Tests importing a pasted list through the batch.
   */
  public function testList(): void {
    $form_state = (new FormState())->setValues($this->values([
      'source' => ImportOptions::SOURCE_LIST,
      'list' => "form.com 100\nform.net\nnot a domain",
      'price' => ['number' => '40', 'currency_code' => 'EUR'],
      'offers' => NULL,
    ]));
    $this->submit($form_state);
    $this->assertSame([], $form_state->getErrors());

    $this->assertEquals(new Price('100', 'EUR'), $this->variation('form.com')->getPrice());
    $this->assertEquals(new Price('40', 'EUR'), $this->variation('form.net')->getPrice());
    $this->assertFalse((bool) $this->variation('form.com')->get('sale_offers')->value);

    $report = $this->lastReport();
    $this->assertSame(['form.com', 'form.net'], $report->created);
    $this->assertSame('not', $report->errors[0]['domain']);
    $messages = $this->container->get('messenger')->all();
    $this->assertSame('Created 2 domains, updated 0 and skipped 0; 1 with errors.', (string) $messages['status'][0]);
    $this->assertSame('One row could not be imported; the report below says why.', (string) $messages['warning'][0]);

    // The page shows the report, and starts from the same choices.
    $form = $this->container->get('form_builder')->getForm(ImportForm::class);
    $this->assertSame('One row with an error', (string) $form['report']['errors']['#caption']);
    $this->assertSame([[3, 'not', 'Not a registrable domain name.']], $form['report']['errors']['#rows']);
    $this->assertSame('40', $form['defaults']['price']['#default_value']['number']);
    $this->assertSame('EUR', $form['defaults']['price']['#default_value']['currency_code']);
    $this->assertSame(0, (int) $form['defaults']['offers']['#default_value']);

    // Long lists go to Drush.
    $list = implode("\n", array_map(fn(int $i) => "name$i.com", range(1, 10001)));
    $form_state = (new FormState())->setValues($this->values(['source' => ImportOptions::SOURCE_LIST, 'list' => $list]));
    $this->submit($form_state);
    $this->assertStringStartsWith('10001 rows are more than the 10000 this page takes', (string) $form_state->getErrors()['source']);
  }

  /**
   * Tests uploading a CSV file, and refusing one without a domain column.
   */
  public function testCsv(): void {
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $upload = function (string $content) use ($request) {
      $path = $this->siteDirectory . '/upload.csv';
      file_put_contents($path, $content);
      $request->files->set('files', ['csv' => new UploadedFile($path, 'upload.csv', 'text/csv', NULL, TRUE)]);
    };

    $upload("price\n10\n");
    $form_state = (new FormState())->setValues($this->values(['source' => ImportOptions::SOURCE_CSV]));
    $this->submit($form_state);
    $this->assertStringStartsWith('No domain column', (string) $form_state->getErrors()['csv']);

    $upload("Domain Name,Selling Price\nuploaded.com,300\n");
    $form_state = (new FormState())->setValues($this->values(['source' => ImportOptions::SOURCE_CSV, 'dry_run' => 1]));
    $this->submit($form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertNull($this->product('uploaded.com'), 'A dry run saves nothing.');
    $this->assertSame(['uploaded.com'], $this->lastReport()->created);
    $this->assertTrue($this->lastReport()->dryRun);

    $form_state = (new FormState())->setValues($this->values(['source' => ImportOptions::SOURCE_CSV]));
    $this->submit($form_state);
    $this->assertEquals(new Price('300', 'USD'), $this->variation('uploaded.com')->getPrice());
  }

  /**
   * Tests reading a registrar account, then importing what it holds.
   */
  public function testRegistrar(): void {
    Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar'])->save();
    $this->container->get('state')->set('commerce_domains_test.registrations', [
      'held.com' => 1_820_000_000,
      'kept.com' => NULL,
    ]);

    // Importing before reading the account is refused.
    $form_state = (new FormState())->setValues($this->values([
      'source' => ImportOptions::SOURCE_REGISTRAR,
      'provider' => 'reg',
    ]));
    $this->submit($form_state);
    $this->assertSame('Read the account first.', (string) $form_state->getErrors()['provider']);

    $form_state = (new FormState())->setValues([
      'source' => ImportOptions::SOURCE_REGISTRAR,
      'provider' => 'reg',
      'read_account' => 'Read the account',
    ]);
    // The rebuilt form keeps the account in its cached state, as on a POST.
    $form_state->setRequestMethod('POST');
    $this->submit($form_state);
    $account = $form_state->get('account');
    $this->assertSame(['held.com', 'kept.com'], $account['unknown']);
    $form = $this->container->get('form_builder')->rebuildForm('commerce_domains_import', $form_state);
    $this->assertSame("held.com\nkept.com", $form['account']['names']['#default_value']);

    // Only some of the account's domains, one with its own price.
    $form_state = (new FormState())->setValues($this->values([
      'source' => ImportOptions::SOURCE_REGISTRAR,
      'provider' => 'reg',
      'names' => "held.com 900\nelsewhere.com",
      'price' => ['number' => '10', 'currency_code' => 'USD'],
    ]));
    $form_state->set('account', $account);
    $this->submit($form_state);
    $this->assertSame([], $form_state->getErrors());
    $product = $this->product('held.com');
    $this->assertSame('reg', $product->get('domain_registrar')->target_id);
    $this->assertSame(1_820_000_000, (int) $product->get('domain_expires')->value);
    $this->assertEquals(new Price('900', 'USD'), $this->variation('held.com')->getPrice());
    $this->assertNull($this->product('kept.com'), 'A line taken out is left out.');
    $this->assertSame('Not in the Reg account.', $this->lastReport()->errors[0]['message']);
  }

  /**
   * Tests the menu link, and the link from a provider's Registrations tab.
   */
  public function testLinks(): void {
    $hooks = $this->container->get(ImportHooks::class);
    $alter = function (array $links) use ($hooks): array {
      $hooks->menuLinksDiscoveredAlter($links);
      return $links;
    };
    $links = $alter([
      'commerce_domains_import.import' => ['parent' => 'commerce.admin_commerce', 'title' => 'Import domains'],
    ]);
    $this->assertSame('commerce.admin_commerce', $links['commerce_domains_import.import']['parent'], 'Without the domain overview, under Commerce.');
    $links = $alter($links + ['views_view:views.commerce_domains_overview.page_1' => ['parent' => 'commerce.admin_commerce']]);
    $this->assertSame('views_view:views.commerce_domains_overview.page_1', $links['commerce_domains_import.import']['parent']);
    $this->assertSame('Import', (string) $links['commerce_domains_import.import']['title']);

    $provider = Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar']);
    $provider->save();
    $this->container->get('state')->set('commerce_domains_test.registrations', ['held.com' => NULL]);
    $form_state = (new FormState())->set('provider', 'reg')->set('plan', $this->container->get(RegistrarSync::class)->plan($provider));
    $form = $this->registrationsForm($form_state);
    $this->assertSame('Import the domain the catalog lacks', (string) $form['plan']['import']['#title']);
    $this->assertEquals(Url::fromRoute('commerce_domains_import.import', [], ['query' => ['provider' => 'reg']]), $form['plan']['import']['#url']);

    // Not for people who can't import.
    $this->setUpCurrentUser([], []);
    $this->assertArrayNotHasKey('import', $this->registrationsForm($form_state)['plan']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import\Form;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\ImportBatch;
use Drupal\commerce_domains_import\ImportOptions;
use Drupal\commerce_domains_import\ImportParser;
use Drupal\commerce_domains_import\ImportReport;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imports domains from a pasted list, a CSV file or a registrar account.
 *
 * The same as `drush commerce-domains:import`. The rows run in a batch, and
 * the report of the last import stays on the page. A registrar account is
 * read when asked, never on page load.
 */
final class ImportForm extends FormBase {

  // Rows per batch request; fewer when each row waits for an RDAP lookup.
  protected const BATCH_SIZE = 50;

  protected const LOOKUP_BATCH_SIZE = 10;

  // Domains listed per section of the report.
  protected const REPORT_NAMES = 200;

  // The most rows the form takes: the batch keeps them all in one database
  // row. Drush takes more.
  protected const MAX_ROWS = 10000;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Catalog $catalog,
    protected DomainImporter $importer,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(Catalog::class),
      $container->get(DomainImporter::class),
      $container->get('tempstore.private'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $types = $this->productTypeOptions();
    $stores = $this->storeOptions();
    if (!$types || !$stores) {
      $form['setup'] = [
        '#markup' => '<p>' . ($types
          ? $this->t('Add a store before importing domains.')
          : $this->t("No product type sells domains yet: turn on the <em>Domain name</em> trait in a product type's settings.")) . '</p>',
      ];
      return $form;
    }

    $last = $this->tempStore()->get('report');
    if (is_array($last) && ($last['report'] ?? NULL) instanceof ImportReport) {
      $form['report'] = $this->buildReport($last['report'], (int) $last['time']);
    }

    // Choices start from the last import's; a registrar's Registrations tab
    // links here with ?provider= to import the account's other domains.
    $choices = (array) ($this->tempStore()->get('choices') ?? []);
    $registrars = $this->registrarOptions();
    $requested = (string) $this->getRequest()->query->get('provider', '');
    $source = isset($registrars[$requested]) ? ImportOptions::SOURCE_REGISTRAR : ($choices['source'] ?? ImportOptions::SOURCE_LIST);

    $form['source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Domains'),
      '#options' => [
        ImportOptions::SOURCE_LIST => $this->t('Paste a list'),
        ImportOptions::SOURCE_CSV => $this->t('Upload a CSV file'),
        ImportOptions::SOURCE_REGISTRAR => $this->t('From a registrar account'),
      ],
      '#default_value' => $source,
      '#required' => TRUE,
    ];
    if (!$registrars) {
      unset($form['source']['#options'][ImportOptions::SOURCE_REGISTRAR]);
      $form['source']['#description'] = $this->t('To import from a registrar account, add the registrar with its API at Commerce → Configuration → Domains → Domain providers.');
      if ($source === ImportOptions::SOURCE_REGISTRAR) {
        $form['source']['#default_value'] = ImportOptions::SOURCE_LIST;
      }
    }

    $form['list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Domains'),
      '#title_display' => 'invisible',
      '#description' => $this->t('One domain per line, optionally followed by its price: <em>example.com 1500</em> or <em>example.net, 2500 EUR</em>.'),
      '#rows' => 10,
      '#states' => ['visible' => [':input[name="source"]' => ['value' => ImportOptions::SOURCE_LIST]]],
    ];

    $form['csv'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV file'),
      '#description' => $this->t('A header row names the columns, in any order and case: <em>domain</em> (or <em>domain name</em>; required), <em>price</em> (or <em>selling price</em>), <em>currency</em>, <em>buy_now</em> and <em>offers</em> (yes or no), <em>minimum_offer</em>, <em>description</em>, <em>registrar</em> (a domain provider, by name or ID), <em>expires</em> (a date), <em>atom_premium</em> and <em>internal</em> (yes or no). Other columns are left to the site.'),
      '#attributes' => ['accept' => '.csv,.txt,text/csv'],
      '#states' => ['visible' => [':input[name="source"]' => ['value' => ImportOptions::SOURCE_CSV]]],
    ];

    if ($registrars) {
      $form['account'] = [
        '#type' => 'container',
        '#states' => ['visible' => [':input[name="source"]' => ['value' => ImportOptions::SOURCE_REGISTRAR]]],
      ];
      $form['account']['provider'] = [
        '#type' => 'select',
        '#title' => $this->t('Registrar'),
        '#options' => $registrars,
        '#default_value' => isset($registrars[$requested]) ? $requested : ($choices['provider'] ?? NULL),
      ];
      $form['account']['read'] = [
        '#type' => 'submit',
        '#value' => $this->t('Read the account'),
        '#name' => 'read_account',
        '#submit' => ['::readAccount'],
        '#limit_validation_errors' => [['source'], ['provider']],
      ];
      $account = $form_state->get('account');
      if (is_array($account) && isset($registrars[$account['provider']])) {
        $count = count($account['unknown']);
        $args = ['@label' => $registrars[$account['provider']]];
        $form['account']['names'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Domains to import'),
          '#description' => $this->formatPlural($count, 'The domain in the @label account that the catalog lacks. Remove any line to leave that domain out; a price after a domain sets its price.', 'The @count domains in the @label account that the catalog lacks. Remove any line to leave that domain out; a price after a domain sets its price.', $args),
          '#default_value' => implode("\n", $account['unknown']),
          '#rows' => min(20, max(5, $count)),
        ];
        if (!$count) {
          $form['account']['names']['#description'] = $this->t('Every domain in the @label account is in the catalog already.', $args);
        }
      }
    }

    $form['defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('New domains'),
      '#description' => $this->t('For new domains whose row leaves these out.'),
      '#open' => TRUE,
    ];
    $form['defaults']['product_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Product type'),
      '#options' => $types,
      '#default_value' => isset($types[$choices['product_type'] ?? '']) ? $choices['product_type'] : array_key_first($types),
      '#access' => count($types) > 1,
    ];
    /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');
    $default_store = $store_storage->loadDefault();
    $store = isset($stores[$choices['store'] ?? '']) ? $choices['store'] : ($default_store ? $default_store->id() : array_key_first($stores));
    $form['defaults']['store'] = [
      '#type' => 'select',
      '#title' => $this->t('Store'),
      '#options' => $stores,
      '#default_value' => $store,
      '#access' => count($stores) > 1,
    ];
    /** @var \Drupal\commerce_store\Entity\StoreInterface|null $store_entity */
    $store_entity = $store_storage->load($store);
    $form['defaults']['price'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Price'),
      '#default_value' => [
        'number' => (string) ($choices['price'] ?? ''),
        'currency_code' => (string) ($choices['currency'] ?? $store_entity?->getDefaultCurrencyCode() ?? 'USD'),
      ],
      '#description' => $this->importer->hasPriceRules()
        ? $this->t('For rows without a price that no pricing rule matches. The currency is also that of prices without one.')
        : $this->t('For rows without a price. The currency is also that of prices without one.'),
    ];
    $form['defaults']['buy_now'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Buy now'),
      '#description' => $this->t('Sell at the price straight away. Without it, new domains only take offers.'),
      '#default_value' => $choices['buy_now'] ?? TRUE,
    ];
    $form['defaults']['offers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Accept offers'),
      '#default_value' => $choices['offers'] ?? TRUE,
    ];
    $form['defaults']['published'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#description' => $this->t('Put new domains on sale at once.'),
      '#default_value' => $choices['published'] ?? TRUE,
    ];
    $form['defaults']['internal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Internal'),
      '#description' => $this->t('Domains you keep for your own use: never for sale, on this site or on marketplaces, so they stay unpublished.'),
      '#default_value' => $choices['internal'] ?? FALSE,
    ];
    $form['defaults']['published']['#states']['disabled'][':input[name="internal"]'] = ['checked' => TRUE];

    $form['existing'] = [
      '#type' => 'radios',
      '#title' => $this->t('Domains already in the catalog'),
      '#options' => [
        ImportOptions::SKIP => $this->t('Skip them'),
        ImportOptions::UPDATE => $this->t('Update their price and sale terms from the row'),
      ],
      ImportOptions::UPDATE => [
        '#description' => $this->t('What the row leaves out stays as it is; so does whether the domain is published.'),
      ],
      '#default_value' => $choices['existing'] ?? ImportOptions::SKIP,
    ];
    $form['lookup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Look up registrars'),
      '#description' => $this->t("For domains without a registrar, ask the registry's public RDAP service, and record the domain provider of that name with the expiry date. Takes about a second a domain; some ccTLDs, such as .io, have no RDAP service."),
      '#default_value' => $choices['lookup'] ?? FALSE,
    ];
    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run'),
      '#description' => $this->t('Report what the import would do, without saving anything.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Builds the report of the last import.
   */
  protected function buildReport(ImportReport $report, int $time): array {
    $args = ['@time' => $this->dateFormatter->format($time, 'short')];
    $build = [
      '#type' => 'details',
      '#title' => $report->dryRun ? $this->t('Last dry run, @time', $args) : $this->t('Last import, @time', $args),
      '#open' => TRUE,
      '#weight' => -100,
    ];
    $build['summary'] = ['#markup' => '<p>' . ImportBatch::summary($report) . '</p>'];
    if ($report->errors) {
      $build['errors'] = [
        '#type' => 'table',
        '#caption' => $this->formatPlural(count($report->errors), 'One row with an error', '@count rows with errors'),
        '#header' => [$this->t('Line'), $this->t('Domain'), $this->t('Error')],
        '#rows' => array_map(fn(array $error) => [$error['line'] ?: '', $error['domain'], $error['message']], $report->errors),
      ];
    }
    if ($report->notes) {
      $items = [];
      foreach ($report->notes as $message => $count) {
        $items[] = $count > 1 ? $this->t('@message (@count domains)', ['@message' => $message, '@count' => $count]) : $message;
      }
      $build['notes'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('To look at'),
        '#items' => $items,
      ];
    }
    $sections = [
      'created' => [$report->dryRun ? $this->t('To create') : $this->t('Created'), $report->created],
      'updated' => [$report->dryRun ? $this->t('To update') : $this->t('Updated'), $report->updated],
      'skipped' => [
        $this->t('Skipped'),
        array_map(fn(string $name, string $reason) => $name . ' (' . $reason . ')', array_keys($report->skipped), $report->skipped),
      ],
    ];
    foreach ($sections as $key => [$title, $names]) {
      if (!$names) {
        continue;
      }
      $items = array_slice($names, 0, self::REPORT_NAMES);
      if (count($names) > self::REPORT_NAMES) {
        $items[] = $this->t('and @count more', ['@count' => count($names) - self::REPORT_NAMES]);
      }
      $build[$key] = [
        '#type' => 'details',
        '#title' => $this->t('@title (@count)', ['@title' => $title, '@count' => count($names)]),
        'list' => ['#theme' => 'item_list', '#items' => $items],
      ];
    }
    return $build;
  }

  /**
   * Reads the chosen registrar account, keeping its domains for the rebuild.
   */
  public function readAccount(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
    $form_state->set('account', NULL);
    $provider = $this->provider((string) $form_state->getValue('provider'));
    if (!$provider) {
      return;
    }
    try {
      $plan = $this->importer->readAccount($provider);
    }
    catch (ProviderException $e) {
      $this->messenger()->addError($this->t('@label could not be read: @error', [
        '@label' => $provider->label(),
        '@error' => $e->getMessage(),
      ]));
      return;
    }
    $form_state->set('account', [
      'provider' => (string) $provider->id(),
      'unknown' => $plan->unknown,
      'expires' => array_map(fn(Registration $registration) => $registration->expires, $plan->registrations),
    ]);
    // Show this account's domains, not what was typed for another.
    $input = $form_state->getUserInput();
    unset($input['names']);
    $form_state->setUserInput($input);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'read_account') {
      return;
    }
    $options = $this->options($form_state);
    // The form elements the options come from.
    $elements = [
      'product_type' => 'product_type',
      'store' => 'store',
      'currency' => 'price][currency_code',
      'price' => 'price][number',
      'existing' => 'existing',
    ];
    foreach ($this->importer->validateOptions($options) as $key => $message) {
      $form_state->setErrorByName($elements[$key] ?? '', $message);
    }

    $rows = [];
    switch ($options->source) {
      case ImportOptions::SOURCE_LIST:
        $rows = ImportParser::parseList((string) $form_state->getValue('list'));
        if (!$rows) {
          $form_state->setErrorByName('list', $this->t('Enter at least one domain.'));
        }
        break;

      case ImportOptions::SOURCE_CSV:
        $file = $this->upload();
        if (!$file) {
          $form_state->setErrorByName('csv', $this->t('Choose a CSV file to upload.'));
          break;
        }
        try {
          $rows = ImportParser::parseCsv((string) file_get_contents($file->getPathname()));
        }
        catch (\InvalidArgumentException $e) {
          $form_state->setErrorByName('csv', $e->getMessage());
          break;
        }
        if (!$rows) {
          $form_state->setErrorByName('csv', $this->t('The file has no rows below its header.'));
        }
        break;

      case ImportOptions::SOURCE_REGISTRAR:
        $account = $form_state->get('account');
        $provider = $this->provider((string) $form_state->getValue('provider'));
        if (!$provider || !is_array($account) || $account['provider'] !== $provider->id()) {
          $form_state->setErrorByName('provider', $this->t('Read the account first.'));
          break;
        }
        $rows = $this->importer->accountRows(ImportParser::parseList((string) $form_state->getValue('names')), $provider, $account['expires']);
        if (!$rows) {
          $form_state->setErrorByName('names', $this->t('Enter at least one domain.'));
        }
        break;
    }
    if (count($rows) > self::MAX_ROWS) {
      $form_state->setErrorByName('source', $this->t('@count rows are more than the @max this page takes: import them with <code>drush commerce-domains:import</code>.', [
        '@count' => count($rows),
        '@max' => self::MAX_ROWS,
      ]));
    }
    $form_state->set('rows', $rows);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $options = $this->options($form_state);
    $rows = (array) $form_state->get('rows');
    $store = $this->tempStore();
    $store->set('choices', [
      'source' => $options->source,
      'provider' => $form_state->getValue('provider'),
      'product_type' => $options->productType,
      'store' => $options->store,
      'price' => $options->price,
      'currency' => $options->currency,
      'buy_now' => $options->buyNow,
      'offers' => $options->offers,
      'published' => $options->published,
      'internal' => $options->internal,
      'existing' => $options->existing,
      'lookup' => $options->lookupRegistrars,
    ]);
    $store->delete('report');

    $batch = (new BatchBuilder())
      ->setTitle($options->dryRun ? $this->t('Checking domains') : $this->t('Importing domains'))
      ->setInitMessage($this->formatPlural(count($rows), 'One domain to go', '@count domains to go'))
      ->setProgressMessage('')
      ->setFinishCallback(ImportBatch::class . ':finished');
    foreach (array_chunk($rows, $options->lookupRegistrars ? self::LOOKUP_BATCH_SIZE : self::BATCH_SIZE) as $chunk) {
      $batch->addOperation(ImportBatch::class . ':process', [$chunk, $options, count($rows)]);
    }
    batch_set($batch->toArray());
  }

  /**
   * Returns the import's choices as submitted.
   */
  protected function options(FormStateInterface $form_state): ImportOptions {
    $price = (array) $form_state->getValue('price', []);
    $number = trim((string) ($price['number'] ?? ''));
    return new ImportOptions(
      productType: (string) $form_state->getValue('product_type'),
      store: (int) $form_state->getValue('store'),
      currency: (string) ($price['currency_code'] ?? ''),
      price: $number === '' ? NULL : $number,
      buyNow: (bool) $form_state->getValue('buy_now'),
      offers: (bool) $form_state->getValue('offers'),
      published: (bool) $form_state->getValue('published'),
      internal: (bool) $form_state->getValue('internal'),
      existing: (string) $form_state->getValue('existing'),
      lookupRegistrars: (bool) $form_state->getValue('lookup'),
      dryRun: (bool) $form_state->getValue('dry_run'),
      source: (string) $form_state->getValue('source'),
    );
  }

  /**
   * Returns the uploaded CSV file, if one arrived.
   */
  protected function upload(): ?UploadedFile {
    $files = $this->getRequest()->files->get('files', []);
    $file = is_array($files) ? ($files['csv'] ?? NULL) : NULL;
    return $file instanceof UploadedFile && $file->isValid() ? $file : NULL;
  }

  /**
   * Returns the domain product types, as options.
   *
   * @return array<string, string>
   *   Labels keyed by product type ID.
   */
  protected function productTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('commerce_product_type')->loadMultiple($this->catalog->productTypes()) as $id => $type) {
      $options[(string) $id] = (string) $type->label();
    }
    return $options;
  }

  /**
   * Returns the stores, as options.
   *
   * @return array<int|string, string>
   *   Names keyed by store ID.
   */
  protected function storeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('commerce_store')->loadMultiple() as $id => $store) {
      $options[$id] = (string) $store->label();
    }
    return $options;
  }

  /**
   * Returns the enabled providers whose registrar API reads the account.
   *
   * @return array<string, string>
   *   Labels keyed by provider ID.
   */
  protected function registrarOptions(): array {
    $options = [];
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
      if ($provider->status() && $provider->hasCapability(RegistrarInterface::class)) {
        $options[(string) $id] = (string) $provider->label();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Loads an enabled registrar provider, with its settings.php overrides.
   */
  protected function provider(string $id): ?ProviderInterface {
    if (!isset($this->registrarOptions()[$id])) {
      return NULL;
    }
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null */
    return $this->entityTypeManager->getStorage('commerce_domains_provider')->load($id);
  }

  /**
   * Returns the store of the last report and choices.
   */
  protected function tempStore(): PrivateTempStore {
    return $this->tempStoreFactory->get(ImportBatch::STORE);
  }

}

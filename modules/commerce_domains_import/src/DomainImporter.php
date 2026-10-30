<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\RegistrarPlan;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_import\Event\DomainImportEvent;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Adds domains to the catalog, or updates the ones already there.
 *
 * Each row is checked (a registrable name, not repeated, readable values),
 * matched with the catalog by domain, and built into a product and its
 * variation. Subscribers to DomainImportEvent then map the site's own
 * columns, and the domain is saved. A dry run does all of it but the saving.
 *
 * A new domain gets the row's price, or the pricing rules' price (Commerce
 * Domains Pricing), or the import's default price. A domain already in the
 * catalog is skipped, or updated with the row's price and sale terms; what
 * the row leaves out stays as it is.
 *
 * A row that can't be imported throws \InvalidArgumentException within this
 * class, with a translated message: it goes to the import report, for the
 * person importing, and is never logged.
 */
class DomainImporter {

  use StringTranslationTrait;

  // Rows handled between entity cache resets.
  protected const CHUNK_SIZE = 50;

  // The text format of descriptions when the field doesn't limit formats.
  protected const BODY_FORMAT = 'plain_text';

  /**
   * The codes of the enabled currencies, once loaded.
   *
   * @var string[]|null
   */
  protected ?array $currencies = NULL;

  /**
   * Marketplace provider IDs by lowercase ID and name, once read.
   *
   * @var array<string, string>|null
   */
  protected ?array $marketplaces = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Catalog $catalog,
    protected DomainNameParser $parser,
    protected Availability $availability,
    protected RegistrarSync $registrarSync,
    protected RegistrarLookup $registrarLookup,
    protected EventDispatcherInterface $eventDispatcher,
    protected Connection $database,
    protected LoggerInterface $logger,
    protected ?PriceRules $priceRules = NULL,
  ) {}

  /**
   * Whether pricing rules price new domains whose row has no price.
   */
  public function hasPriceRules(): bool {
    return $this->priceRules !== NULL;
  }

  /**
   * Checks the choices an import runs with.
   *
   * @return array<string, string>
   *   What is wrong, keyed by product_type, store, currency, price or
   *   existing; empty when all is well.
   */
  public function validateOptions(ImportOptions $options): array {
    $errors = [];
    if (!in_array($options->productType, $this->catalog->productTypes(), TRUE)) {
      $errors['product_type'] = $options->productType === ''
        ? (string) $this->t("No product type sells domains yet: turn on the Domain name trait in a product type's settings.")
        : (string) $this->t('@type is not a product type with the Domain name trait.', ['@type' => $options->productType]);
    }
    if (!$this->entityTypeManager->getStorage('commerce_store')->load($options->store)) {
      $errors['store'] = (string) $this->t('There is no store @id.', ['@id' => $options->store]);
    }
    if (!$this->currencyEnabled($options->currency)) {
      $errors['currency'] = (string) $this->t('@code is not an enabled currency.', ['@code' => $options->currency]);
    }
    if ($options->price !== NULL && (!is_numeric($options->price) || (float) $options->price < 0)) {
      $errors['price'] = (string) $this->t('The default price must be a number of 0 or more.');
    }
    if (!in_array($options->existing, [ImportOptions::SKIP, ImportOptions::UPDATE], TRUE)) {
      $errors['existing'] = (string) $this->t('Domains already in the catalog are skipped or updated, not @choice.', ['@choice' => $options->existing]);
    }
    return $errors;
  }

  /**
   * Reads a registrar account through its plugin, as a registrar sync does.
   *
   * The plan's unknown names are the account's domains the catalog lacks.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function readAccount(ProviderInterface $provider): RegistrarPlan {
    return $this->registrarSync->plan($provider);
  }

  /**
   * Turns rows naming domains into rows for a registrar account's domains.
   *
   * Each is recorded at the registrar, with the expiry date the account
   * gives. A domain the account doesn't hold can't be imported from it.
   *
   * @param \Drupal\commerce_domains_import\ImportRow[] $rows
   *   Rows naming domains, e.g. from ImportParser::parseList().
   * @param \Drupal\commerce_domains\Entity\ProviderInterface $provider
   *   The registrar.
   * @param array<string, int|null> $expires
   *   The expiry of every domain in the account, keyed by domain.
   *
   * @return \Drupal\commerce_domains_import\ImportRow[]
   *   The rows.
   */
  public function accountRows(array $rows, ProviderInterface $provider, array $expires): array {
    $account_rows = [];
    foreach ($rows as $row) {
      $name = DomainName::normalize((string) $row->value('domain'));
      if (!array_key_exists($name, $expires)) {
        $error = (string) $this->t('Not in the @label account.', ['@label' => $provider->label()]);
        $account_rows[] = new ImportRow($row->line, $row->raw, $row->values, $row->error ?? $error);
        continue;
      }
      $values = ['registrar' => (string) $provider->id()] + $row->values;
      if ($expires[$name] !== NULL && $row->value('expires') === NULL) {
        $values['expires'] = (string) $expires[$name];
      }
      $account_rows[] = new ImportRow($row->line, $row->raw, $values, $row->error);
    }
    return $account_rows;
  }

  /**
   * Imports rows, adding what happened to the report.
   *
   * Call it once per chunk of a large import with the same report, so that
   * repeated domains are caught across chunks.
   *
   * @param \Drupal\commerce_domains_import\ImportRow[] $rows
   *   The rows.
   * @param \Drupal\commerce_domains_import\ImportOptions $options
   *   The import's choices, as validateOptions() accepts them.
   * @param \Drupal\commerce_domains_import\ImportReport $report
   *   The report to add to.
   */
  public function import(array $rows, ImportOptions $options, ImportReport $report): void {
    $valid = [];
    foreach ($rows as $row) {
      $domain = (string) $row->value('domain');
      if ($row->error !== NULL) {
        $report->error($row->line, $domain, $row->error);
        continue;
      }
      $parsed = $domain === '' ? NULL : $this->parser->parse($domain);
      if (!$parsed) {
        $report->error($row->line, $domain, $domain === '' ? (string) $this->t('No domain.') : (string) $this->t('Not a registrable domain name.'));
        continue;
      }
      if (isset($report->seen[$parsed->name])) {
        $report->error($row->line, $domain, (string) $this->t('Repeats line @line.', ['@line' => $report->seen[$parsed->name]]));
        continue;
      }
      $report->seen[$parsed->name] = $row->line;
      $valid[] = [$row, $parsed];
    }

    foreach (array_chunk($valid, self::CHUNK_SIZE) as $chunk) {
      $existing = $this->existing(array_map(fn(array $item) => $item[1]->name, $chunk));
      foreach ($chunk as [$row, $parsed]) {
        try {
          $this->importRow($row, $parsed, $existing[$parsed->name] ?? NULL, $options, $report);
        }
        catch (\InvalidArgumentException $e) {
          $report->error($row->line, (string) $row->value('domain'), $e->getMessage());
        }
      }
      // Keeps memory flat on long files, and drops what a dry run changed.
      $this->entityTypeManager->getStorage('commerce_product')->resetCache();
      $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    }
  }

  /**
   * Creates, updates or skips the domain of one row.
   *
   * @throws \InvalidArgumentException
   *   When the row can't be imported; the message says why.
   */
  protected function importRow(ImportRow $row, DomainName $parsed, ?ProductInterface $product, ImportOptions $options, ImportReport $report): void {
    $name = $parsed->name;
    $is_new = $product === NULL;
    if ($product && $options->existing !== ImportOptions::UPDATE) {
      $report->skipped[$name] = (string) $this->t('in the catalog');
      return;
    }
    if ($product) {
      $variation = $this->variation($product);
      if (!$variation) {
        throw new \InvalidArgumentException((string) new TranslatableMarkup('The domain in the catalog has no variation to update.'));
      }
    }
    else {
      [$product, $variation] = $this->create($row, $parsed, $options);
    }
    $this->apply($row, $product, $variation, $options, $is_new);

    $lookup_note = NULL;
    if ($options->lookupRegistrars && $row->value('registrar') === NULL && $product->get('domain_registrar')->isEmpty()) {
      $lookup_note = $this->lookUpRegistrar($name, $row, $product);
    }

    $event = new DomainImportEvent($row->raw, $product, $variation, $is_new, $options, $row->line);
    try {
      $this->eventDispatcher->dispatch($event, DomainImportEvent::NAME);
    }
    catch (\InvalidArgumentException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      // One row's failing site mapping shouldn't stop a long import.
      $this->logger->error('The site mapping failed on @domain: @error', [
        '@domain' => $name,
        '@error' => $e->getMessage(),
      ]);
      throw new \InvalidArgumentException((string) new TranslatableMarkup("The site's own mapping failed: @error", ['@error' => $e->getMessage()]), 0, $e);
    }
    if ($event->getError() !== NULL) {
      throw new \InvalidArgumentException($event->getError());
    }
    if (!$variation->getPrice()) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('No price: the row has none, and no default price applies.'));
    }
    // A subscriber may have found the registrar after all.
    if ($lookup_note !== NULL && !self::hasRegistrar($product)) {
      $report->note($lookup_note);
    }

    $product_changed = $is_new || $product->hasTranslationChanges();
    $variation_changed = $is_new || $variation->hasTranslationChanges();
    if (!$product_changed && !$variation_changed) {
      $report->skipped[$name] = (string) $this->t('no changes');
      return;
    }
    if (!$options->dryRun) {
      $this->save($product, $variation, $product_changed, $variation_changed);
    }
    if ($is_new) {
      $report->created[] = $name;
    }
    else {
      $report->updated[] = $name;
    }
  }

  /**
   * Builds a new domain's product and variation, unsaved.
   *
   * @return array{0: \Drupal\commerce_product\Entity\ProductInterface, 1: \Drupal\commerce_product\Entity\ProductVariationInterface}
   *   The product and its variation.
   */
  protected function create(ImportRow $row, DomainName $parsed, ImportOptions $options): array {
    /** @var \Drupal\commerce_product\Entity\ProductTypeInterface $type */
    $type = $this->entityTypeManager->getStorage('commerce_product_type')->load($options->productType);
    $variation_type_id = $type->getVariationTypeIds()[0] ?? 'default';
    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface|null $variation_type */
    $variation_type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load($variation_type_id);
    $title = self::title((string) $row->value('domain'), $parsed);

    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->create([
      'type' => $variation_type_id,
      'sku' => $this->sku($parsed->name),
      'status' => TRUE,
    ]);
    if (!$variation_type || !$variation_type->shouldGenerateTitle()) {
      $variation->setTitle($title);
    }
    // In stock, where the variation keeps stock (Commerce Escrow's trait).
    if ($variation->hasField('in_stock')) {
      $variation->set('in_stock', TRUE);
    }

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $this->entityTypeManager->getStorage('commerce_product')->create([
      'type' => $options->productType,
      'title' => $title,
      'stores' => [$options->store],
      'status' => $options->published,
      'variations' => [$variation],
    ]);
    return [$product, $variation];
  }

  /**
   * Sets the row's values on a domain, and for a new one the defaults.
   *
   * @throws \InvalidArgumentException
   *   When a value can't be read.
   */
  protected function apply(ImportRow $row, ProductInterface $product, ProductVariationInterface $variation, ImportOptions $options, bool $is_new): void {
    $premium = self::read($row, 'atom_premium', ImportParser::flag(...));
    $internal = self::read($row, 'internal', ImportParser::flag(...));
    $buy_now = self::read($row, 'buy_now', ImportParser::flag(...));
    $offers = self::read($row, 'offers', ImportParser::flag(...));
    $marketplaces = self::read($row, 'marketplaces', ImportParser::flag(...));
    $not_listed_on = self::read($row, 'not_listed_on', $this->marketplaceIds(...));
    $floor = self::read($row, 'floor_price', ImportParser::amount(...));
    $lease = self::read($row, 'lease_months', ImportParser::months(...));
    $amount = self::read($row, 'price', ImportParser::amount(...));
    $minimum = self::read($row, 'minimum_offer', ImportParser::amount(...));
    $expires = self::read($row, 'expires', ImportParser::date(...));
    $currency = $row->value('currency') !== NULL ? strtoupper((string) $row->value('currency')) : NULL;

    if ($premium !== NULL && $product->hasField('atom_premium')) {
      $product->set('atom_premium', $premium);
    }
    // Internal domains go unpublished when saved.
    if ($product->hasField('domain_internal') && ($internal !== NULL || $is_new)) {
      $product->set('domain_internal', $internal ?? $options->internal);
    }

    // A price without a currency is in the row's currency, else in the one
    // the domain is priced in, else in the import's.
    $fallback = $currency ?? ($is_new ? $options->currency : ($variation->getPrice()?->getCurrencyCode() ?? $options->currency));
    if ($amount !== NULL) {
      $variation->setPrice($this->price($amount, $fallback));
    }
    elseif ($is_new && ($price = $this->defaultPrice($product, $options))) {
      $variation->setPrice($price);
    }

    // New domains take the import's sale terms where the row has none.
    if ($variation->hasField('sale_buy_now') && ($buy_now !== NULL || $is_new)) {
      $variation->set('sale_buy_now', $buy_now ?? $options->buyNow);
    }
    if ($variation->hasField('sale_offers') && ($offers !== NULL || $is_new)) {
      $variation->set('sale_offers', $offers ?? $options->offers);
    }
    if ($variation->hasField('sale_marketplaces') && ($marketplaces !== NULL || $is_new)) {
      $variation->set('sale_marketplaces', $marketplaces ?? $options->marketplaces);
    }
    if ($not_listed_on !== NULL && $variation->hasField('sale_not_listed_on')) {
      $variation->set('sale_not_listed_on', $not_listed_on);
    }
    if ($minimum !== NULL && $variation->hasField('sale_minimum_offer')) {
      $variation->set('sale_minimum_offer', $this->price($minimum, $variation->getPrice()?->getCurrencyCode() ?? $fallback));
    }
    if ($floor !== NULL && $variation->hasField('sale_floor_price')) {
      $variation->set('sale_floor_price', $this->price($floor, $variation->getPrice()?->getCurrencyCode() ?? $fallback));
    }
    if ($lease !== NULL && $variation->hasField('sale_lease_months')) {
      $variation->set('sale_lease_months', $lease);
    }

    if (($description = $row->value('description')) !== NULL && $product->hasField('body')) {
      $product->set('body', ['value' => $description, 'format' => $this->bodyFormat($product)]);
    }
    if (($registrar = $row->value('registrar')) !== NULL) {
      $provider = $this->registrarLookup->provider($registrar);
      if ($provider === NULL) {
        throw new \InvalidArgumentException((string) new TranslatableMarkup('No registrar @registrar: add it at Commerce → Configuration → Domains → Domain providers, or leave the cell empty.', [
          '@registrar' => $registrar,
        ]));
      }
      $product->set('domain_registrar', $provider);
    }
    if ($expires !== NULL) {
      $product->set('domain_expires', $expires);
    }
    // The registrar account holds the domain: that shows it is the seller's.
    if ($options->source === ImportOptions::SOURCE_REGISTRAR) {
      $product->set('domain_verified', TRUE);
    }
  }

  /**
   * Records the registrar RDAP names for a domain, and its expiry date.
   *
   * @return string|null
   *   What to note when the registrar stays unknown, or NULL.
   */
  protected function lookUpRegistrar(string $name, ImportRow $row, ProductInterface $product): ?string {
    try {
      $record = $this->registrarLookup->lookup($name);
    }
    catch (LookupException $e) {
      return $e->getMessage();
    }
    if (!$record) {
      return (string) $this->t('RDAP has no record of some domains; check that they are registered.');
    }
    if ($record->expires !== NULL && $row->value('expires') === NULL && $product->get('domain_expires')->isEmpty()) {
      $product->set('domain_expires', $record->expires);
    }
    if ($record->registrar === NULL) {
      return (string) $this->t('RDAP names no registrar for some domains.');
    }
    $provider = $this->registrarLookup->provider($record->registrar);
    if ($provider === NULL) {
      return (string) $this->t('No provider for the registrar @registrar: add one at Commerce → Configuration → Domains → Domain providers, then import again with updates to record it.', ['@registrar' => $record->registrar]);
    }
    $product->set('domain_registrar', $provider);
    return NULL;
  }

  /**
   * Saves a domain in one transaction.
   *
   * @throws \InvalidArgumentException
   *   When saving failed.
   */
  protected function save(ProductInterface $product, ProductVariationInterface $variation, bool $product_changed, bool $variation_changed): void {
    $transaction = $this->database->startTransaction();
    try {
      // The variation first: a new product then references it by ID.
      if ($variation_changed) {
        $variation->save();
      }
      if ($product_changed) {
        $product->save();
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Importing @domain failed: @error', [
        '@domain' => $product->getTitle(),
        '@error' => $e->getMessage(),
      ]);
      throw new \InvalidArgumentException((string) new TranslatableMarkup('Not saved: @error', ['@error' => $e->getMessage()]), 0, $e);
    }
  }

  /**
   * Loads the catalog's products for some domains.
   *
   * @param string[] $names
   *   Normalized domains.
   *
   * @return array<string, \Drupal\commerce_product\Entity\ProductInterface>
   *   One product per domain, keyed by domain: the one for sale, else the
   *   newest.
   */
  protected function existing(array $names): array {
    $types = $this->catalog->productTypes();
    if (!$names || !$types) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $types, 'IN')
      ->condition('domain', $names, 'IN')
      ->sort('product_id')
      ->execute();
    $products = [];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($storage->loadMultiple($ids) as $product) {
      $name = (string) $product->get('domain')->value;
      if (!isset($products[$name]) || !$this->variation($products[$name], TRUE) || $this->variation($product, TRUE)) {
        $products[$name] = $product;
      }
    }
    return $products;
  }

  /**
   * Returns the variation an import updates: the one for sale, else the first.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param bool $for_sale
   *   Only return a variation that is for sale.
   */
  protected function variation(ProductInterface $product, bool $for_sale = FALSE): ?ProductVariationInterface {
    $variations = $product->getVariations();
    foreach ($variations as $variation) {
      if ($this->availability->isForSale($product, $variation)) {
        return $variation;
      }
    }
    return $variations && !$for_sale ? reset($variations) : NULL;
  }

  /**
   * Returns the price of a new domain whose row has none.
   *
   * The pricing rules' price when one matches the domain, else the import's
   * default price, if it has one.
   */
  protected function defaultPrice(ProductInterface $product, ImportOptions $options): ?Price {
    // The rules count words from the name as written, so they get the title.
    $atom_premium = $product->hasField('atom_premium') && (bool) $product->get('atom_premium')->value;
    $price = $this->priceRules?->price($product->getTitle(), $atom_premium);
    if (!$price && $options->price !== NULL && $options->price !== '') {
      $price = new Price($options->price, $options->currency);
    }
    return $price;
  }

  /**
   * Builds a price from an amount read by ImportParser::amount().
   *
   * @param array{0: string, 1: string|null} $amount
   *   The number, and the currency the cell named.
   * @param string $currency
   *   The currency when the cell named none.
   *
   * @throws \InvalidArgumentException
   *   When the currency isn't enabled.
   */
  protected function price(array $amount, string $currency): Price {
    [$number, $code] = $amount;
    $code ??= $currency;
    if (!$this->currencyEnabled($code)) {
      throw new \InvalidArgumentException((string) new TranslatableMarkup('@code is not an enabled currency.', ['@code' => $code]));
    }
    return new Price($number, $code);
  }

  /**
   * Returns a SKU for a new domain: its name, made unique when taken.
   */
  protected function sku(string $name): string {
    $query = fn(string $sku) => $this->entityTypeManager->getStorage('commerce_product_variation')->getQuery()
      ->accessCheck(FALSE)
      ->condition('sku', $sku)
      ->range(0, 1)
      ->execute();
    $sku = $name;
    for ($suffix = 2; $query($sku); $suffix++) {
      $sku = $name . '-' . $suffix;
    }
    return $sku;
  }

  /**
   * Returns the text format for descriptions: the first the field allows.
   */
  protected function bodyFormat(ProductInterface $product): string {
    $formats = $product->getFieldDefinition('body')?->getSetting('allowed_formats');
    return is_array($formats) && $formats ? (string) reset($formats) : self::BODY_FORMAT;
  }

  /**
   * Whether a currency is enabled.
   */
  protected function currencyEnabled(string $code): bool {
    $this->currencies ??= array_values($this->entityTypeManager->getStorage('commerce_currency')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->execute());
    return in_array($code, $this->currencies, TRUE);
  }

  /**
   * Whether a domain has its registrar recorded.
   */
  protected static function hasRegistrar(ProductInterface $product): bool {
    return !$product->get('domain_registrar')->isEmpty();
  }

  /**
   * Reads a list of marketplaces, by ID or name.
   *
   * @return string[]
   *   Provider IDs.
   *
   * @throws \InvalidArgumentException
   *   When one isn't a marketplace provider.
   */
  protected function marketplaceIds(string $value): array {
    if ($this->marketplaces === NULL) {
      $this->marketplaces = [];
      /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
      foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
        if ($provider->hasCapability(MarketplaceInterface::class)) {
          $this->marketplaces[mb_strtolower((string) $id)] = (string) $id;
          $this->marketplaces[mb_strtolower((string) $provider->label())] = (string) $id;
        }
      }
    }
    $ids = [];
    foreach (ImportParser::items($value) as $item) {
      $id = $this->marketplaces[mb_strtolower($item)] ?? NULL;
      if ($id === NULL) {
        throw new \InvalidArgumentException((string) new TranslatableMarkup('No marketplace @name.', ['@name' => $item]));
      }
      $ids[$id] = $id;
    }
    return array_values($ids);
  }

  /**
   * Reads a cell with a parser, or returns NULL when it is empty.
   *
   * @throws \InvalidArgumentException
   *   When the parser can't read the cell.
   */
  protected static function read(ImportRow $row, string $column, callable $parse): mixed {
    $value = $row->value($column);
    return $value === NULL ? NULL : $parse($value);
  }

  /**
   * Returns the product title: the name as given, in Unicode for IDNs.
   */
  protected static function title(string $given, DomainName $parsed): string {
    $title = rtrim(trim($given), '.');
    return str_contains(mb_strtolower($title), 'xn--') ? $parsed->unicode : $title;
  }

}

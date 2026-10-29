<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_pricing\Kernel;

use Drupal\commerce_domains_pricing\CurrencyPrices;
use Drupal\commerce_domains_pricing\EventSubscriber\VariationSubscriber;
use Drupal\commerce_domains_pricing\Form\SettingsForm;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_domains_pricing\Rounding;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_pricelist\Entity\PriceList;
use Drupal\commerce_pricelist\Entity\PriceListInterface;
use Drupal\commerce_pricelist\Entity\PriceListItem;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests prices in other currencies, into a price list and price fields.
 */
#[CoversClass(CurrencyPrices::class)]
#[CoversClass(VariationSubscriber::class)]
#[CoversClass(SettingsForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class CurrencyPricesTest extends DomainsKernelTestBase {

  use PricingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_pricelist', 'commerce_domains_pricing'];

  /**
   * The disabled list that gets the prices.
   */
  protected PriceListInterface $list;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Rates from State, where Commerce Exchanger would have its own.
    $container->register('commerce_exchanger.calculate', TestExchanger::class)
      ->addArgument(new Reference('state'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_pricelist');
    $this->installEntitySchema('commerce_pricelist_item');
    $this->installConfig(['commerce_domains_pricing']);
    // USD comes with the store, EUR with the base class.
    $this->container->get('commerce_price.currency_importer')->import('GBP');

    $this->list = PriceList::create([
      'type' => 'commerce_product_variation',
      'name' => 'Marketplace prices',
      'stores' => [$this->store],
      'status' => FALSE,
    ]);
    $this->list->save();
    $this->config(PriceRules::CONFIG)
      ->set('price_list', $this->list->uuid())
      ->set('field_prefix', 'field_price_')
      ->save();
    $this->setNameAcreRounding();
  }

  /**
   * Tests prices converted, rounded, and written on each save.
   */
  public function testPrices(): void {
    $product = $this->createDomain('Rounded.com', 1234);
    $variation = $this->variation($product);
    $this->assertPrice('1234', 'USD', $variation->getPrice(), 'The price itself is kept as entered.');
    // 1,110.60 euros, without the cents, up to 1,199.
    $this->assertPrice('1199', 'EUR', $this->field($variation));
    $this->assertSame(['EUR' => '1199', 'GBP' => '999', 'USD' => '1299'], $this->items($variation));
    $ids = $this->itemIds($variation);

    $variation->setPrice(new Price('20000', 'USD'))->save();
    $variation = $this->variation($product);
    $this->assertPrice('18000', 'EUR', $this->field($variation));
    $this->assertSame(['EUR' => '18000', 'GBP' => '16000', 'USD' => '20000'], $this->items($variation));
    $this->assertSame($ids, $this->itemIds($variation), 'The same items, updated.');
  }

  /**
   * Tests that syncing saves keep the stored prices.
   */
  public function testSyncingSaves(): void {
    $product = $this->createDomain('Migrated.com', 1234);

    // A migration brings its own euro price, and a new price.
    $variation = $this->variation($product);
    $variation->setPrice(new Price('5000', 'USD'));
    $variation->set('field_price_eur', new Price('4444', 'EUR'));
    $variation->setSyncing(TRUE);
    $variation->save();
    $variation = $this->variation($product);
    $this->assertPrice('4444', 'EUR', $this->field($variation));
    $this->assertSame(['EUR' => '4444', 'GBP' => '999', 'USD' => '1299'], $this->items($variation), 'The list follows the field; the rest is kept.');

    // An empty field empties the list's item.
    $variation->set('field_price_eur', NULL);
    $variation->setSyncing(TRUE);
    $variation->save();
    $this->assertSame(['GBP' => '999', 'USD' => '1299'], $this->items($this->variation($product)));

    // The next save works them out again.
    $this->variation($product)->save();
    $this->assertSame(['EUR' => '4499', 'GBP' => '3999', 'USD' => '4999'], $this->items($this->variation($product)));
  }

  /**
   * Tests choosing currencies, missing rates, and cleaning up items.
   */
  public function testCurrencies(): void {
    $product = $this->createDomain('Choice.com', 1234);
    $variation = $this->variation($product);
    // Items the module didn't write: a tier older than a euro price, which
    // is the one to keep, and a pound price.
    $this->entityTypeManager->getStorage('commerce_pricelist_item')->delete($this->loadItems($variation));
    $this->addItem($variation, new Price('2', 'EUR'), 5);
    $this->addItem($variation, new Price('1', 'EUR'));
    $this->addItem($variation, new Price('3', 'GBP'));

    // Only dollars and euros; yen is chosen but not enabled.
    $this->config(PriceRules::CONFIG)->set('currencies', ['USD', 'EUR', 'JPY'])->save();
    $variation->save();
    $this->assertSame(['EUR' => '1199', 'USD' => '1299'], $this->items($variation));

    // Without a rate, a currency keeps what it has.
    $this->config(PriceRules::CONFIG)->set('currencies', [])->save();
    $variation->save();
    $this->assertSame(['EUR' => '1199', 'GBP' => '999', 'USD' => '1299'], $this->items($variation));
    $this->container->get('state')->set(TestExchanger::STATE, ['USD' => ['EUR' => '0.9']]);
    $variation->setPrice(new Price('2000', 'USD'))->save();
    $this->assertSame(['EUR' => '1799', 'GBP' => '999', 'USD' => '1999'], $this->items($variation));

    // Without a price list or a field prefix, nothing is written.
    $this->config(PriceRules::CONFIG)->set('price_list', NULL)->set('field_prefix', '')->save();
    $variation->setPrice(new Price('3000', 'USD'))->save();
    $this->assertSame(['EUR' => '1799', 'GBP' => '999', 'USD' => '1999'], $this->items($variation));
    $this->assertPrice('1799', 'EUR', $this->field($this->variation($product)));
  }

  /**
   * Tests updating every domain from the settings page.
   */
  public function testUpdateEveryDomain(): void {
    $product = $this->createDomain('Every.com', 1234);
    $this->assertSame(['EUR' => '1199', 'GBP' => '999', 'USD' => '1299'], $this->items($this->variation($product)));

    // To the nearest 1,000, in place of NameAcre's two steps.
    $form_state = (new FormState())->setValues([
      'steps' => [
        0 => ['up_to' => '10000', 'method' => 'up', 'multiple' => '100', 'remove' => 1],
        1 => ['up_to' => '', 'method' => 'nearest', 'multiple' => '100', 'remove' => 1],
        2 => ['up_to' => '', 'method' => 'nearest', 'multiple' => '1000', 'subtract' => '', 'max' => ''],
      ],
      'update' => 1,
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(['EUR' => '1000', 'GBP' => '1000', 'USD' => '1000'], $this->items($this->variation($product)));
    $this->assertPrice('1000', 'EUR', $this->field($this->variation($product)));
    $this->assertPrice('1234', 'USD', $this->variation($product)->getPrice());
  }

  /**
   * Tests that without Commerce Exchanger only the own currency is priced.
   */
  public function testWithoutExchanger(): void {
    $variation = $this->variation($this->createDomain('Plain.com', 1234));
    $currency_prices = new CurrencyPrices(
      $this->container->get('config.factory'),
      $this->entityTypeManager,
      $this->container->get(Rounding::class),
    );
    $prices = $currency_prices->calculate($variation);
    $this->assertSame(['USD'], array_keys($prices));
    $this->assertPrice('1299', 'USD', $prices['USD']);
  }

  /**
   * Tests that the prices are NameAcre's, as its subscriber worked them out.
   */
  public function testSameAsNameAcre(): void {
    $rates = ['EUR' => '0.9137', 'GBP' => '0.7891'];
    $this->container->get('state')->set(TestExchanger::STATE, ['USD' => $rates]);
    foreach (['2', '99', '1234', '1250.75', '9999', '10000', '10049.5', '12345', '99999', '1000000'] as $delta => $number) {
      $variation = $this->variation($this->createDomain("same$delta.com", (float) $number));
      $expected = ['USD' => (string) self::nameAcreRound((int) $number)];
      foreach ($rates as $currency => $rate) {
        // Converted and rounded to cents, then cast to an integer.
        $converted = Calculator::round(Calculator::multiply($number, $rate), 2);
        $expected[$currency] = (string) self::nameAcreRound((int) $converted);
      }
      ksort($expected);
      $this->assertSame($expected, $this->items($variation), $number);
      $this->assertPrice($expected['EUR'], 'EUR', $this->field($variation));
    }
  }

  /**
   * Returns a product's variation, freshly loaded.
   */
  protected function variation(ProductInterface $product): ProductVariationInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $storage->resetCache();
    $ids = $product->getVariationIds();
    $variation = $storage->load(reset($ids));
    $this->assertInstanceOf(ProductVariationInterface::class, $variation);
    return $variation;
  }

  /**
   * Returns a variation's euro price field.
   */
  protected function field(ProductVariationInterface $variation): ?Price {
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem|null $item */
    $item = $variation->get('field_price_eur')->first();
    return $item?->toPrice();
  }

  /**
   * Returns a variation's items in the list, as numbers keyed by currency.
   */
  protected function items(ProductVariationInterface $variation): array {
    $prices = [];
    foreach ($this->loadItems($variation) as $item) {
      $this->assertTrue($item->isEnabled());
      $this->assertEquals(1, $item->getQuantity());
      $currency = $item->getPrice()->getCurrencyCode();
      $this->assertArrayNotHasKey($currency, $prices, 'One item per currency.');
      $prices[$currency] = Calculator::trim($item->getPrice()->getNumber());
    }
    ksort($prices);
    return $prices;
  }

  /**
   * Returns the IDs of a variation's items in the list.
   */
  protected function itemIds(ProductVariationInterface $variation): array {
    $ids = array_keys($this->loadItems($variation));
    sort($ids);
    return $ids;
  }

  /**
   * Loads a variation's items in the list.
   *
   * @return \Drupal\commerce_pricelist\Entity\PriceListItemInterface[]
   *   The items, keyed by ID.
   */
  protected function loadItems(ProductVariationInterface $variation): array {
    $storage = $this->entityTypeManager->getStorage('commerce_pricelist_item');
    $storage->resetCache();
    /** @var \Drupal\commerce_pricelist\Entity\PriceListItemInterface[] $items */
    $items = $storage->loadByProperties([
      'price_list_id' => $this->list->id(),
      'purchasable_entity' => $variation->id(),
    ]);
    return $items;
  }

  /**
   * Adds an item to the list.
   */
  protected function addItem(ProductVariationInterface $variation, Price $price, int $quantity = 1): void {
    PriceListItem::create([
      'type' => 'commerce_product_variation',
      'price_list_id' => $this->list->id(),
      'purchasable_entity' => $variation->id(),
      'quantity' => $quantity,
      'price' => $price,
    ])->save();
  }

}

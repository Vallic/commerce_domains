<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\PriceResolver;
use Drupal\commerce_exchanger\Exception\ExchangeRatesDataMismatchException;
use Drupal\commerce_exchanger\ExchangerCalculatorInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_pricelist\Entity\PriceList;
use Drupal\commerce_pricelist\Entity\PriceListInterface;
use Drupal\commerce_pricelist\Entity\PriceListItem;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests prices from the variation, a price list and exchange rates.
 */
#[CoversClass(PriceResolver::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class PriceResolverTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_pricelist'];

  /**
   * The domain's variation, priced at $1,299.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected ProductVariationInterface $variation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_pricelist');
    $this->installEntitySchema('commerce_pricelist_item');
    $this->container->get('commerce_price.currency_importer')->import('GBP');
    $variations = $this->createDomain('a.com', 1299)->getVariations();
    $this->variation = reset($variations);
  }

  /**
   * Creates a disabled price list, which the storefront never applies.
   */
  protected function priceList(array $values = []): PriceListInterface {
    $list = PriceList::create($values + [
      'type' => 'commerce_product_variation',
      'name' => 'Marketplace prices',
      'stores' => [$this->store],
      'status' => FALSE,
    ]);
    $list->save();
    return $list;
  }

  /**
   * Adds an item to a price list.
   */
  protected function addItem(PriceListInterface $list, Price $price, array $values = []): void {
    PriceListItem::create($values + [
      'type' => 'commerce_product_variation',
      'price_list_id' => $list->id(),
      'purchasable_entity' => $this->variation->id(),
      'quantity' => 1,
      'price' => $price,
    ])->save();
  }

  /**
   * Returns a resolver that converts to euros at 0.9, and knows no other rate.
   */
  protected function resolver(bool $exchanger = TRUE): PriceResolver {
    $calculator = new class() implements ExchangerCalculatorInterface {

      /**
       * {@inheritdoc}
       */
      public function priceConversion(Price $price, string $target_currency) {
        if ($target_currency !== 'EUR') {
          throw new ExchangeRatesDataMismatchException('No rate.');
        }
        return $price->convert('EUR', '0.9');
      }

      /**
       * {@inheritdoc}
       */
      public function getExchangeRates() {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getExchangerId() {
        return 'test';
      }

    };
    return new PriceResolver($this->entityTypeManager, $this->container->get('datetime.time'), $exchanger ? $calculator : NULL);
  }

  /**
   * Tests the variation price alone, and converted where rates exist.
   */
  public function testVariationPrice(): void {
    $this->assertSame(['USD' => 1299.0], $this->resolver(FALSE)->prices($this->variation));
    $this->assertEquals(['USD' => 1299.0, 'EUR' => 1169.1], $this->resolver()->prices($this->variation));
    $this->assertSame(['USD' => 1299.0], $this->resolver(FALSE)->prices($this->variation, 'no-such-list'));
  }

  /**
   * Tests that a price list's items win, at a quantity of one.
   */
  public function testPriceList(): void {
    $list = $this->priceList();
    $this->addItem($list, new Price('1199', 'EUR'));
    $this->addItem($list, new Price('1250', 'USD'));
    $this->addItem($list, new Price('900', 'GBP'), ['quantity' => 5]);
    $this->addItem($list, new Price('800', 'GBP'), ['status' => FALSE]);

    $prices = $this->resolver()->prices($this->variation, (string) $list->uuid());
    $this->assertEquals(['EUR' => 1199.0, 'USD' => 1250.0], $prices, 'Tiers and disabled items are left out; GBP has no rate.');

    $catalog = new Catalog($this->entityTypeManager, $this->container->get('Drupal\commerce_domains\Availability'), $this->resolver(FALSE));
    $this->assertSame(1199.0, $catalog->load(['a.com'], (string) $list->uuid())['a.com']->price('EUR'));
    $this->assertSame(0.0, $catalog->load(['a.com'])['a.com']->price('EUR'));
  }

  /**
   * Tests that a list outside its dates gives no prices.
   */
  public function testListDates(): void {
    $ended = $this->priceList([
      'start_date' => (new DrupalDateTime('-2 months'))->format('Y-m-d\TH:i:s'),
      'end_date' => (new DrupalDateTime('-1 month'))->format('Y-m-d\TH:i:s'),
    ]);
    $this->addItem($ended, new Price('1199', 'EUR'));
    $this->assertSame(['USD' => 1299.0], $this->resolver(FALSE)->prices($this->variation, (string) $ended->uuid()));
  }

}

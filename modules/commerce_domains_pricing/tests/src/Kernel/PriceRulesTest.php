<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_pricing\Kernel;

use Drupal\commerce_domains_pricing\EventSubscriber\VariationSubscriber;
use Drupal\commerce_domains_pricing\PriceRule;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests matching pricing rules, and pricing domains saved without a price.
 */
#[CoversClass(PriceRules::class)]
#[CoversClass(PriceRule::class)]
#[CoversClass(VariationSubscriber::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class PriceRulesTest extends DomainsKernelTestBase {

  use PricingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_pricing'];

  /**
   * The rules service.
   */
  protected PriceRules $priceRules;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['commerce_domains_pricing']);
    $this->priceRules = $this->container->get(PriceRules::class);
  }

  /**
   * Sets the rules, in order.
   */
  protected function setRules(PriceRule ...$rules): void {
    $this->config(PriceRules::CONFIG)->set('rules', array_map(fn(PriceRule $rule) => $rule->toConfig(), $rules))->save();
  }

  /**
   * Tests that the first rule that matches gives the price.
   */
  public function testMatching(): void {
    $this->assertNull($this->priceRules->price('example.com'), 'No rules, no price.');

    $this->setRules(
      new PriceRule(new Price('5000', 'USD'), atomPremium: TRUE),
      new PriceRule(new Price('2999', 'USD'), extensions: ['com'], maxLength: 5),
      new PriceRule(new Price('1999', 'USD'), extensions: ['com'], minWords: 2, maxWords: 2),
      new PriceRule(new Price('899', 'EUR'), extensions: ['de'], minLength: 6, maxLength: 7),
      new PriceRule(new Price('999', 'EUR'), extensions: ['co.uk', 'de']),
      new PriceRule(new Price('499', 'USD'), extensions: ['com', 'net'], atomPremium: FALSE),
    );
    $this->assertPrice('5000', 'USD', $this->priceRules->price('anything.io', TRUE), 'Premium names take the first rule.');
    $this->assertPrice('2999', 'USD', $this->priceRules->price('Short.com'));
    $this->assertPrice('499', 'USD', $this->priceRules->price('Longer.com'), 'Six letters are too long for the short rule.');
    $this->assertPrice('1999', 'USD', $this->priceRules->price('BookShop.com'), 'Two words.');
    $this->assertPrice('1999', 'USD', $this->priceRules->price('book-shop.com'), 'Two words, by the hyphen.');
    $this->assertPrice('499', 'USD', $this->priceRules->price('bookshop.com'), 'In lowercase, one word.');
    $this->assertPrice('999', 'EUR', $this->priceRules->price('example.co.uk'));
    $this->assertPrice('899', 'EUR', $this->priceRules->price('München.de'), 'Seven characters, not the A-label\'s 14.');
    $this->assertPrice('899', 'EUR', $this->priceRules->price('xn--mnchen-3ya.de'));
    $this->assertPrice('999', 'EUR', $this->priceRules->price('Shopping.de'), 'Eight characters.');
    $this->assertNull($this->priceRules->price('example.org'), 'No rule for the extension.');
    $this->assertNull($this->priceRules->price('www.example.com'), 'Not a registrable domain.');
    $this->assertNull($this->priceRules->price('not a domain'));

    $rule = $this->priceRules->match('Short.com');
    $this->assertSame(['com'], $rule?->extensions);
    $this->assertSame(5, $rule->maxLength);
  }

  /**
   * Tests counting words from capitals and hyphens.
   */
  public function testWords(): void {
    $this->assertSame(3, PriceRules::words('LearnPhpOnline'));
    $this->assertSame(2, PriceRules::words('AIChat'));
    $this->assertSame(2, PriceRules::words('Web3Tools'));
    $this->assertSame(3, PriceRules::words('learn-php--online'));
    $this->assertSame(1, PriceRules::words('bookshop'));
    $this->assertSame(1, PriceRules::words('ABC'));
    $this->assertSame(2, PriceRules::words('MünchenTours'));
  }

  /**
   * Tests that domains saved without a price get one, and others keep theirs.
   */
  public function testUnpricedDomains(): void {
    $this->setRules(new PriceRule(new Price('2999', 'USD'), extensions: ['com']));

    // An import saves the variation first, then the product that refers to
    // it, which saves the variation again.
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'new-com', 'status' => TRUE]);
    $variation->save();
    $this->assertNull($variation->getPrice(), 'Without its product, the domain is not known yet.');
    $product = $this->createProduct('New.com', $variation);
    $this->assertPrice('2999', 'USD', $this->variation($product)->getPrice());

    // A domain with a price keeps it.
    $own = $this->createDomain('Own.com', 1500);
    $this->assertPrice('1500', 'USD', $this->variation($own)->getPrice());

    // And so does one the rules priced, when the rules change.
    $this->setRules(new PriceRule(new Price('3999', 'USD'), extensions: ['com']));
    $variation = $this->variation($product);
    $variation->save();
    $this->assertPrice('2999', 'USD', $this->variation($product)->getPrice());

    // No rule matches: no price.
    $org = $this->createProduct('New.org');
    $this->assertNull($this->variation($org)->getPrice());

    // Syncing saves, such as migrations, keep what they bring; the next
    // save prices the domain.
    $this->setRules(new PriceRule(new Price('999', 'USD'), extensions: ['org']));
    $variation = $this->variation($org);
    $variation->setSyncing(TRUE);
    $variation->save();
    $this->assertNull($this->variation($org)->getPrice());
    $this->variation($org)->save();
    $this->assertPrice('999', 'USD', $this->variation($org)->getPrice());
  }

  /**
   * Creates a domain product around a variation without a price.
   */
  protected function createProduct(string $title, ?ProductVariationInterface $variation = NULL): ProductInterface {
    $variation ??= ProductVariation::create(['type' => 'default', 'sku' => strtolower($title), 'status' => TRUE]);
    $product = Product::create([
      'type' => 'default',
      'title' => $title,
      'stores' => [$this->store],
      'variations' => [$variation],
      'status' => TRUE,
    ]);
    $product->save();
    return $product;
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

}

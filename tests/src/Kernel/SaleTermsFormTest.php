<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Form\SaleTermsForm;
use Drupal\commerce_domains\Hook\ProductFormHooks;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingPluginBase;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\SaleTermRules;
use Drupal\commerce_domains\SaleTerms;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests turning automatic leases off, and showing what empty terms come to.
 */
#[CoversClass(SaleTermsForm::class)]
#[CoversClass(SaleTerms::class)]
#[CoversClass(SaleTermRules::class)]
#[CoversClass(ProductFormHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SaleTermsFormTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');
    $this->installConfig(['commerce_domains']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests the checkbox: empty terms follow it, a domain's own never do.
   */
  public function testAutomaticLease(): void {
    $empty = $this->createDomain('empty.com', 9000)->getVariations()[0];
    $own = $this->createDomain('own.com', 9000, [], ['sale_lease_months' => 6])->getVariations()[0];
    $none = $this->createDomain('none.com', 9000, [], ['sale_lease_months' => 0])->getVariations()[0];

    $this->assertTrue(SaleTerms::automaticLease());
    $this->assertSame(12, SaleTerms::fromVariation($empty)->leaseMonthsAt(9000));
    $this->assertNull(SaleTerms::fromVariation($empty)->leaseMonths);

    $form_state = (new FormState())->setValues(['automatic_lease' => NULL, 'op' => 'Save configuration']);
    $this->container->get('form_builder')->submitForm(SaleTermsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertFalse($this->config('commerce_domains.settings')->get('automatic_lease'));

    $this->assertFalse(SaleTerms::automaticLease());
    $this->assertSame(0, SaleTerms::fromVariation($empty)->leaseMonthsAt(9000));
    $this->assertSame(6, SaleTerms::fromVariation($own)->leaseMonthsAt(9000));
    $this->assertSame(0, SaleTerms::fromVariation($none)->leaseMonthsAt(9000));

    // Without the setting, as before it existed: on.
    $this->config('commerce_domains.settings')->delete();
    $this->container->get('config.factory')->reset('commerce_domains.settings');
    $this->assertTrue(SaleTerms::automaticLease());
    $this->container->get('module_handler')->loadInclude('commerce_domains', 'install');
    commerce_domains_update_11006();
    $this->assertTrue($this->config('commerce_domains.settings')->get('automatic_lease'));
  }

  /**
   * Tests the tables.
   */
  public function testTables(): void {
    foreach (['dynadot', 'afternic', 'porkbun'] as $plugin) {
      Provider::create(['id' => $plugin, 'label' => ucfirst($plugin), 'plugin' => $plugin, 'status' => TRUE])->save();
    }
    Provider::create(['id' => 'spaceship', 'label' => 'Spaceship', 'plugin' => 'spaceship', 'status' => FALSE])->save();
    $form = $this->container->get('form_builder')->getForm(SaleTermsForm::class);

    $lease = array_map(fn(array $row) => array_map('strval', $row), $form['lease']['#rows']);
    $this->assertSame([
      ['Below 2,000', 'No lease'],
      ['2,000 to 8,000', '6 months'],
      ['8,000 to 20,000', '12 months'],
      ['20,000 to 50,000', '24 months'],
      ['50,000 and above', '36 months'],
    ], $lease);

    // Enabled marketplaces only, not registrars or those switched off.
    $rows = [];
    foreach ($form['marketplaces']['#rows'] as $row) {
      $rows[(string) $row[0]['data']['#title']] = array_map('strval', array_slice($row, 1, NULL, TRUE));
    }
    $this->assertEqualsCanonicalizing(['Afternic', 'Dynadot'], array_keys($rows));
    $this->assertSame('80% of the price', $rows['Afternic'][1]);
    $this->assertSame('Up to 60 months', $rows['Afternic'][3]);
    $this->assertSame('None', $rows['Dynadot'][1]);
    $this->assertSame('50% of the price below 10,000, 25% above', $rows['Dynadot'][2]);
    $this->assertSame('Up to 12 months', $rows['Dynadot'][3]);
    $this->assertSame('', $rows['Dynadot'][4]);
  }

  /**
   * Tests what the fields say an empty term comes to at the domain's price.
   */
  public function testHints(): void {
    $hints = function (float $usd): array {
      $variation = $this->createDomain('d' . (int) $usd . '.com', $usd)->getVariations()[0];
      $form = $this->container->get('entity.form_builder')->getForm($variation, 'edit');
      $text = [];
      foreach (['sale_lease_months', 'sale_minimum_offer', 'sale_floor_price'] as $field) {
        $text[$field] = (string) $form[$field]['fallback']['#value'];
      }
      return $text;
    };
    $text = $hints(9000);
    $this->assertStringContainsString('up to 12 months at this price', $text['sale_lease_months']);
    $this->assertStringContainsString('4,500 USD', $text['sale_minimum_offer']);
    $this->assertStringContainsString('7,200 USD', $text['sale_floor_price']);
    $this->assertStringContainsString('no lease at this price', $hints(1000)['sale_lease_months']);

    $this->config('commerce_domains.settings')->set('automatic_lease', FALSE)->save();
    $this->assertStringContainsString('no lease, unless a marketplace', $hints(9001)['sale_lease_months']);
  }

  /**
   * Tests general, then provider, then domain, from the forms to the listings.
   */
  public function testResolving(): void {
    $builder = $this->container->get('form_builder');
    $form_state = (new FormState())->setValues([
      'floor_percent' => 75,
      'minimum_offer_break' => 5000,
      'minimum_offer_percent' => 40,
      'minimum_offer_percent_above' => 20,
      'automatic_lease' => NULL,
      'op' => 'Save configuration',
    ]);
    $builder->submitForm(SaleTermsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $general = SaleTermRules::general();
    $this->assertSame([75, 40, 20, 5000, FALSE], [
      $general->floorPercent,
      $general->minimumOfferPercent,
      $general->minimumOfferPercentAbove,
      $general->minimumOfferBreak,
      $general->automaticLease,
    ]);
    $this->assertEquals(1600, SaleTerms::defaultMinimumOffer(4000));

    $afternic = Provider::create(['id' => 'afternic', 'label' => 'Afternic', 'plugin' => 'afternic', 'status' => TRUE]);
    $afternic->save();
    $dynadot = Provider::create(['id' => 'dynadot', 'label' => 'Dynadot', 'plugin' => 'dynadot', 'status' => TRUE]);
    $dynadot->save();
    $fruits = Provider::create(['id' => 'fruits', 'label' => 'Fruits', 'plugin' => 'fruits', 'status' => TRUE]);
    $fruits->save();

    // The form offers what the marketplace has, and keeps only what is set.
    $form_object = fn(ProviderInterface $provider) => $this->container->get('entity_type.manager')
      ->getFormObject('commerce_domains_provider', 'edit')
      ->setEntity($provider);
    $form = $builder->getForm($form_object($afternic));
    $this->assertSame(75, $form['sale_terms']['floor_percent']['#placeholder']);
    $this->assertSame(60, $form['sale_terms']['lease_months']['#max']);
    $this->assertArrayNotHasKey('floor_percent', $builder->getForm($form_object($dynadot))['sale_terms']);
    $this->assertArrayNotHasKey('sale_terms', $builder->getForm($form_object($fruits)));

    $form_state = (new FormState())->setValues([
      'sale_terms' => [
        'floor_percent' => '60',
        'minimum_offer_percent' => '',
        'minimum_offer_percent_above' => '',
        'automatic_lease' => '1',
        'lease_months' => '6',
      ],
      'op' => 'Save',
    ]);
    $builder->submitForm($form_object($afternic), $form_state);
    $this->assertSame([], $form_state->getErrors());
    $saved = Provider::load('afternic');
    $this->assertSame(['floor_percent' => 60, 'automatic_lease' => TRUE, 'lease_months' => 6], $saved->getSaleTerms());
    $plugin = $saved->getPlugin();
    $this->assertInstanceOf(ListingPluginBase::class, $plugin);
    $rules = $plugin->saleTermRules();
    $this->assertSame([60, 40, TRUE, 6], [
      $rules->floorPercent,
      $rules->minimumOfferPercent,
      $rules->automaticLease,
      $rules->leaseLimit,
    ]);

    $empty = $this->createDomain('empty.com', 9000);
    $own = $this->createDomain('own.com', 9000, [], [
      'sale_floor_price' => new Price('5000', 'USD'),
      'sale_lease_months' => 24,
    ]);
    $catalog = $this->container->get(Catalog::class);
    $items = $catalog->load(['empty.com', 'own.com']);
    $at = function (string $id, string $name) use ($items): array {
      $plugin = Provider::load($id)->getPlugin();
      $this->assertInstanceOf(MarketplaceInterface::class, $plugin);
      return $plugin->buildListing($items[$name]);
    };

    // Afternic has its own: a 60% floor, and leases on though they are off in
    // general, shortened to 6 months. The domain's own come first. Its price
    // factor of 1.1 raises both floors, so 60% of 9,900 and 5,000 raised.
    $this->assertSame(5940, $at('afternic', 'empty.com')['floorPrice'] / 1_000_000);
    $this->assertSame(6, $at('afternic', 'empty.com')['maxLeasePeriod']);
    $this->assertSame(5500, $at('afternic', 'own.com')['floorPrice'] / 1_000_000);
    $this->assertSame(24, $at('afternic', 'own.com')['maxLeasePeriod']);

    // Dynadot has none, so the general: no lease, and 20% from 5,000, of its
    // price raised by its factor of 1.05.
    $this->assertFalse($at('dynadot', 'empty.com')['installment']);
    $this->assertSame(1890, $at('dynadot', 'empty.com')['minimum_offer']);

    // This site uses the general terms, whatever the marketplaces do.
    $this->assertSame(0, SaleTerms::fromVariation($empty->getVariations()[0])->leaseMonthsAt(9000));
    $this->assertSame(24, SaleTerms::fromVariation($own->getVariations()[0])->leaseMonthsAt(9000));

    $table = $builder->getForm(SaleTermsForm::class)['marketplaces']['#rows'];
    $row = array_values(array_filter($table, fn(array $row) => (string) $row[0]['data']['#title'] === 'Afternic'))[0];
    $this->assertSame('60% of the price', (string) $row[1]);
    $this->assertSame('Up to 6 months', (string) $row[3]);
    $this->assertSame('floor, lease', $row[4]);
  }

  /**
   * Tests adding the general rules to a site that has only the checkbox.
   */
  public function testUpdate(): void {
    $this->container->get('config.storage')->write('commerce_domains.settings', ['automatic_lease' => FALSE]);
    $this->container->get('config.factory')->reset('commerce_domains.settings');
    $this->container->get('module_handler')->loadInclude('commerce_domains', 'install');
    commerce_domains_update_11007();
    $this->assertSame([
      'automatic_lease' => FALSE,
      'floor_percent' => 80,
      'minimum_offer_percent' => 50,
      'minimum_offer_percent_above' => 25,
      'minimum_offer_break' => 10000,
    ], $this->config('commerce_domains.settings')->getRawData());
  }

}

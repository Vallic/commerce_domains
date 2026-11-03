<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\Form\SaleTermsForm;
use Drupal\commerce_domains\Hook\ProductFormHooks;
use Drupal\commerce_domains\SaleTerms;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests turning automatic leases off, and showing what empty terms come to.
 */
#[CoversClass(SaleTermsForm::class)]
#[CoversClass(SaleTerms::class)]
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
      $rows[(string) $row[0]] = array_map('strval', $row);
    }
    $this->assertEqualsCanonicalizing(['Afternic', 'Dynadot'], array_keys($rows));
    $this->assertSame('80% of the price', $rows['Afternic'][1]);
    $this->assertSame('60 months', $rows['Afternic'][3]);
    $this->assertSame('None', $rows['Dynadot'][1]);
    $this->assertSame('Half the price below 10,000, a quarter above', $rows['Dynadot'][2]);
    $this->assertSame('12 months', $rows['Dynadot'][3]);
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
    $this->assertStringContainsString('automatic leases are off', $hints(9001)['sale_lease_months']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\Event\MarketplaceEvents;
use Drupal\commerce_domains_marketplace\Form\DomainMarketplacesForm;
use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Form\FormState;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Marketplaces tab on a domain.
 */
#[CoversClass(DomainMarketplacesForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainMarketplacesFormTest extends MarketplaceKernelTestBase {

  use UserCreationTrait;

  /**
   * Tests the statuses, and checking and pushing the domain.
   */
  public function testForm(): void {
    // Dates on the page need the date formats.
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], ['administer commerce_domains providers']);
    $this->createProvider('test');
    Provider::create(['id' => 'daaz', 'label' => 'DaaZ', 'plugin' => 'daaz'])->save();
    Provider::create(['id' => 'dan', 'label' => 'Dan', 'marketplace' => TRUE])->save();
    Provider::create(['id' => 'hover', 'label' => 'Hover'])->save();
    $product = $this->createDomain('Example.com', 1000);
    $this->assertTrue(DomainMarketplacesForm::access($product)->isAllowed());

    $form_builder = $this->container->get('form_builder');
    $form = $form_builder->getForm(DomainMarketplacesForm::class, $product);
    $this->assertSame('For sale, and listed on every marketplace below.', (string) $form['summary']['#markup']);
    $this->assertSame('Not checked yet', (string) $form['listings']['#rows']['test'][1]);
    $this->assertSame('In the CSV file', (string) $form['listings']['#rows']['daaz'][1]);
    $this->assertSame('To list', (string) $form['listings']['#rows']['dan'][1]);
    $this->assertSame('No API: list or remove it there by hand.', (string) $form['listings']['#rows']['dan'][2]);
    $this->assertArrayNotHasKey('hover', $form['listings']['#rows'], 'A registrar only.');

    // Checking reads, and records what is pending.
    $form_state = (new FormState())->addBuildInfo('args', [$product])->setValues(['op' => 'Check marketplaces']);
    $form_builder->submitForm(DomainMarketplacesForm::class, $form_state);
    $this->assertSame([], $this->listings());
    $form = $form_builder->getForm(DomainMarketplacesForm::class, $product);
    $this->assertSame('Out of date', (string) $form['listings']['#rows']['test'][1]);
    $this->assertSame('Not listed yet.', (string) $form['listings']['#rows']['test'][2]);

    $form_state = (new FormState())->addBuildInfo('args', [$product])->setValues(['op' => 'Push to marketplaces']);
    $form_builder->submitForm(DomainMarketplacesForm::class, $form_state);
    $this->assertSame(['example.com' => ['price' => 1000]], $this->listings());
    $form = $form_builder->getForm(DomainMarketplacesForm::class, $product);
    $this->assertSame('Listed', (string) $form['listings']['#rows']['test'][1]);
    $this->assertNotSame('', $form['listings']['#rows']['test'][4], 'When it was pushed.');
  }

  /**
   * Tests a sale the check finds, and a marketplace that fails.
   */
  public function testSaleAndFailure(): void {
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], ['administer commerce_domains providers']);
    $this->createProvider('test');
    $this->createProvider('other', plugin: 'test_marketplace_other');
    $product = $this->createDomain('Example.com', 1000);
    $this->setListings(['example.com' => ['price' => 1000, 'status' => 'sold']]);
    $this->container->get('state')->set('commerce_domains_marketplace_test.fail', 'retryable');

    $form_builder = $this->container->get('form_builder');
    $form_state = (new FormState())->addBuildInfo('args', [$product])->setValues(['op' => 'Check marketplaces']);
    $form_builder->submitForm(DomainMarketplacesForm::class, $form_state);
    $messages = $this->container->get('messenger')->all();
    $this->assertSame('Test shows it sold (sold): it is now off sale here.', (string) $messages['warning'][0]);
    $this->assertSame('Other: Test marketplace failed (retryable).', (string) $messages['error'][0]);
    $this->assertEqualsCanonicalizing([
      self::soldEvent('test', 'example.com', (int) $product->id(), 'sold'),
      [MarketplaceEvents::SYNC_FAILED, 'other', ['' => 'Test marketplace failed (retryable).']],
    ], $this->events());

    $form = $form_builder->getForm(DomainMarketplacesForm::class, Product::load($product->id()));
    $this->assertSame('Sold on Test: off sale here, and the other marketplaces remove its listings. Put it back on sale if the sale falls through.', (string) $form['summary']['#markup']);
    $this->assertSame('Sold', (string) $form['listings']['#rows']['test'][1]);
    $this->assertStringStartsWith('sold, found on ', (string) $form['listings']['#rows']['test'][2]);
  }

}

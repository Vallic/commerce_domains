<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_bulk\Kernel;

use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the bulk form on the Domains overview.
 */
#[CoversFunction('commerce_domains_bulk_install')]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class OverviewBulkFormTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
    'advancedqueue',
    'commerce_domains_marketplace',
    'commerce_domains_pricing',
    'commerce_domains_bulk',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installSchema('commerce_domains_marketplace', [ListingRecorder::TABLE]);
    $this->installConfig([
      'commerce_product',
      'commerce_domains_marketplace',
      'commerce_domains_pricing',
      'commerce_domains_bulk',
    ]);
    $this->createUser();
    $this->setUpCurrentUser([], ['access commerce_product overview', 'administer commerce_product']);
  }

  /**
   * Tests that the overview offers the domain actions and Commerce's.
   */
  public function testBulkForm(): void {
    $this->createDomain('Selected.com');
    $view = Views::getView('commerce_domains_overview');
    $build = $view->preview('page_1');
    $this->render($build);
    $this->assertPageHas('With selection');
    $labels = [
      'Apply pricing rules',
      'Reprice by a factor',
      'Set sale terms',
      'Choose marketplaces',
      'Publish product',
      'Unpublish product',
    ];
    foreach ($labels as $label) {
      $this->assertPageHas($label);
    }
    $this->assertNoRaw('Delete product');
    $this->assertRaw('Selected.com');
  }

  /**
   * Tests adding the bulk form to an overview from before it had one.
   */
  public function testInstall(): void {
    $view = View::load('commerce_domains_overview');
    $display = &$view->getDisplay('default');
    unset($display['display_options']['fields']['commerce_product_bulk_form'], $display['display_options']['style']['options']['columns']['commerce_product_bulk_form']);
    $view->save();

    $this->container->get('module_handler')->loadInclude('commerce_domains_bulk', 'install');
    commerce_domains_bulk_install(FALSE);
    $display = View::load('commerce_domains_overview')->getDisplay('default');
    $this->assertSame('commerce_product_bulk_form', array_key_first($display['display_options']['fields']));
    $this->assertSame('bulk_form', $display['display_options']['fields']['commerce_product_bulk_form']['plugin_id']);
    $this->assertSame('commerce_product_bulk_form', array_key_first($display['display_options']['style']['options']['columns']));

    // Config imports bring the view as it is meant to be.
    $view = View::load('commerce_domains_overview');
    $display = &$view->getDisplay('default');
    unset($display['display_options']['fields']['commerce_product_bulk_form']);
    $view->save();
    commerce_domains_bulk_install(TRUE);
    $this->assertArrayNotHasKey('commerce_product_bulk_form', View::load('commerce_domains_overview')->getDisplay('default')['display_options']['fields']);
  }

  /**
   * Asserts that the rendered page has a text, as a label or option.
   */
  protected function assertPageHas(string $text): void {
    $this->assertStringContainsString($text, $this->getRawContent());
  }

}

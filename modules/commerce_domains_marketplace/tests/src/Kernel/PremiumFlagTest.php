<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\Hook\MarketplaceHooks;
use Drupal\commerce_domains_marketplace\MarketplaceSync;
use Drupal\commerce_product\Entity\Product;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Atom premium flag following the marketplace's listings.
 */
#[CoversClass(MarketplaceSync::class)]
#[CoversClass(MarketplaceHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class PremiumFlagTest extends MarketplaceKernelTestBase {

  use UserCreationTrait;

  /**
   * Returns a domain's Atom premium flag, as saved.
   */
  protected function flag(string $name): bool {
    $this->entityTypeManager->getStorage('commerce_product')->resetCache();
    $products = Product::loadMultiple();
    foreach ($products as $product) {
      if ($product->get('domain')->value === $name) {
        return (bool) $product->get('atom_premium')->value;
      }
    }
    $this->fail("No product for $name.");
  }

  /**
   * Tests setting the flag from the listings, and locking it on the form.
   */
  public function testFollowsListings(): void {
    $this->createProvider('atom', plugin: 'test_atom');
    $this->createDomain('Premium.com', 1000);
    $this->createDomain('Standard.com', 1000, ['atom_premium' => TRUE]);
    $this->createDomain('Unlisted.com', 1000, ['atom_premium' => TRUE]);
    $this->setListings([
      'premium.com' => ['price' => 5000, 'premium' => TRUE],
      'standard.com' => ['price' => 1000],
    ], 'test_atom');

    $sync = $this->container->get(MarketplaceSync::class);
    $plan = $sync->plan(Provider::load('atom'), dry_run: TRUE);
    $this->assertSame(['premium.com' => TRUE, 'standard.com' => FALSE], $plan->premium);
    $this->assertFalse($this->flag('premium.com'), 'A dry run changes nothing.');

    $sync->plan(Provider::load('atom'));
    $this->assertTrue($this->flag('premium.com'));
    $this->assertFalse($this->flag('standard.com'));
    $this->assertTrue($this->flag('unlisted.com'), 'Not listed there: kept.');
    $this->assertSame([], $sync->plan(Provider::load('atom'), dry_run: TRUE)->premium);

    // Locked on the form while the marketplace is enabled.
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], [], TRUE);
    $product = Product::load(1);
    $form = $this->container->get('entity.form_builder')->getForm($product, 'domain_details');
    $this->assertTrue($form['atom_premium']['#disabled']);
    Provider::load('atom')->setStatus(FALSE)->save();
    $form = $this->container->get('entity.form_builder')->getForm(Product::load(1), 'domain_details');
    $this->assertArrayNotHasKey('#disabled', $form['atom_premium']);
  }

}

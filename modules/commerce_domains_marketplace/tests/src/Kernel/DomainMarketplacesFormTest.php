<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_marketplace\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_marketplace\Form\DomainMarketplacesForm;
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
    $product = $this->createDomain('Example.com', 1000);
    $this->assertTrue(DomainMarketplacesForm::access($product)->isAllowed());

    $form_builder = $this->container->get('form_builder');
    $form = $form_builder->getForm(DomainMarketplacesForm::class, $product);
    $this->assertSame('For sale, and listed on every marketplace below.', (string) $form['summary']['#markup']);
    $this->assertSame('Not checked yet', (string) $form['listings']['#rows']['test'][1]);
    $this->assertSame('In the CSV file', (string) $form['listings']['#rows']['daaz'][1]);

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

}

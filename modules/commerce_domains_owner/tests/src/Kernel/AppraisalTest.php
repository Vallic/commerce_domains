<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains_owner\Appraiser;
use Drupal\commerce_domains_owner\Drush\Commands\AppraisalCommands;
use Drupal\commerce_domains_owner\Hook\ProductHooks;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests appraising the portfolio within the daily allowance.
 */
#[CoversClass(Appraiser::class)]
#[CoversClass(AppraisalCommands::class)]
#[CoversClass(ProductHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class AppraisalTest extends OwnerKernelTestBase {

  use DrushCommandTrait;

  /**
   * The appraiser.
   *
   * @var \Drupal\commerce_domains_owner\Appraiser
   */
  protected Appraiser $appraiser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appraiser = $this->container->get(Appraiser::class);
  }

  /**
   * Reloads a product.
   */
  protected function reload(ProductInterface $product): ProductInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $storage->resetCache([$product->id()]);
    return $storage->load($product->id());
  }

  /**
   * Tests cron: never appraised first, the allowance, and stopping.
   */
  public function testCron(): void {
    $state = $this->container->get('state');
    $abc = $this->createDomain('Abc.com');
    $longer = $this->createDomain('Longer.com');
    $this->createDomain('Sold.com', 1000, ['domain_sold' => $this->now - 86400]);
    $this->assertSame(0, $this->appraiser->cron(), 'Nothing appraises yet.');

    $this->createProvider('appraiser', 'test_appraiser');
    $this->config('commerce_domains_owner.settings')->set('appraisals_per_day', 1)->save();
    $this->assertSame(1, $this->appraiser->cron());
    $abc = $this->reload($abc);
    $this->assertSame('300', $abc->get('domain_appraisal')->number);
    $this->assertSame('USD', $abc->get('domain_appraisal')->currency_code);
    $this->assertEquals(7.5, $abc->get('domain_appraisal_score')->value);
    $this->assertSame(3, (int) $abc->get('domain_tld_count')->value);
    $this->assertSame($this->now, (int) $abc->get('domain_appraised')->value);
    $this->assertSame("+ Short\n− Hyphen", $abc->get('domain_appraisal_notes')->value);
    $this->assertSame(0, $this->appraiser->cron(), "The day's allowance is used up.");

    // The next day: the domain never appraised, not the sold one.
    $state->delete(Appraiser::COUNT_KEY);
    $this->config('commerce_domains_owner.settings')->set('appraisals_per_day', 50)->save();
    $this->assertSame(1, $this->appraiser->cron());
    $this->assertSame(['abc.com', 'longer.com'], $state->get('commerce_domains_test.appraised'));
    $this->assertSame('600', $this->reload($longer)->get('domain_appraisal')->number);
    $this->assertSame([], $this->appraiser->due(10), 'Fresh appraisals stand.');

    // Old ones are redone, oldest first; the service's limit ends the day.
    $abc->set('domain_appraised', $this->now - Appraiser::REFRESH - 1)->save();
    $this->assertSame([(int) $abc->id()], $this->appraiser->due(10));
    $state->set('commerce_domains_test.appraise_limit', 2);
    $this->assertSame(0, $this->appraiser->cron());
    $this->assertSame(0, $this->appraiser->remainingToday(), 'A failure ends the day.');
  }

  /**
   * Tests the Valuation tab and the Drush command.
   */
  public function testFormAndDrush(): void {
    $this->createProvider('appraiser', 'test_appraiser');
    $product = $this->createDomain('Abc.com');
    $this->createDomain('Other.com');

    $this->requireDrush();
    $command = new AppraisalCommands($this->entityTypeManager, $this->appraiser);
    $this->prepareCommand($command);
    $command->appraise(['domains' => 'abc.com,missing.com', 'limit' => 10]);
    $this->assertMatchesRegularExpression('/abc\.com\s+\$300\s+7\.5\s+3/', $this->output->fetch());
    $this->assertSame(['abc.com'], $this->container->get('state')->get('commerce_domains_test.appraised'));
    $this->assertSame(49, $this->appraiser->remainingToday(), 'Drush counts toward the allowance.');

    $this->setUpCurrentUser([], [], TRUE);
    $form = $this->container->get('entity.form_builder')->getForm($this->reload($product), 'edit');
    $this->assertSame('domain_valuation', $form['domain_appraisal']['#group']);
    $this->assertTrue($form['domain_appraisal']['#disabled']);
    $this->assertTrue($form['domain_tld_count']['#disabled']);
  }

}

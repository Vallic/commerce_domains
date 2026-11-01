<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains_import\AccountImport;
use Drupal\commerce_domains_import\Hook\ImportHooks;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests adding a registrar account's new domains, by cron and by setting.
 */
#[CoversClass(AccountImport::class)]
#[CoversClass(ImportHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class AccountImportTest extends ImportKernelTestBase {

  // An expiry date in the account: 2027-03-01.
  protected const EXPIRES = 1803902400;

  /**
   * Tests that cron adds new domains once a day, unpublished and unlisted.
   */
  public function testCron(): void {
    $provider = Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar']);
    $provider->setRegistrationSync(TRUE)->save();
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.registrations', ['new.com' => self::EXPIRES, 'held.com' => self::EXPIRES]);
    $this->createDomain('Held.com');

    // Not set to: nothing is added.
    $this->container->get(AccountImport::class)->cron();
    $this->assertNull($this->product('new.com'));

    $provider->setThirdPartySetting(AccountImport::MODULE, 'add_new', TRUE)->save();
    $this->assertTrue(AccountImport::addsNew($provider));
    $this->container->get(AccountImport::class)->cron();
    $new = $this->product('new.com');
    $this->assertNotNull($new);
    $this->assertFalse($new->isPublished(), 'Unpublished until the owner looks.');
    $this->assertSame('reg', $new->get('domain_registrar')->target_id);
    $this->assertSame(self::EXPIRES, (int) $new->get('domain_expires')->value);
    $this->assertTrue((bool) $new->get('domain_verified')->value);
    $variation = $this->variation('new.com');
    $this->assertFalse((bool) $variation->get('sale_marketplaces')->value, 'Not listed on marketplaces.');
    $this->assertEquals(0, (float) $variation->getPrice()->getNumber(), 'No pricing rule: 0 until the owner prices it.');

    // Once a day: a domain new since waits for tomorrow.
    $state->set('commerce_domains_test.registrations', [
      'new.com' => self::EXPIRES,
      'held.com' => self::EXPIRES,
      'later.com' => NULL,
    ]);
    $this->container->get(AccountImport::class)->cron();
    $this->assertNull($this->product('later.com'));
    $state->set('commerce_domains_import.account_import.reg', 0);
    $this->container->get(AccountImport::class)->cron();
    $this->assertNotNull($this->product('later.com'));

    // A dry run reports and adds nothing.
    $state->set('commerce_domains_test.registrations', ['dry.com' => NULL]);
    $report = $this->container->get(AccountImport::class)->addNew($provider, NULL, TRUE);
    $this->assertSame(['dry.com'], $report->created);
    $this->assertNull($this->product('dry.com'));
  }

  /**
   * Tests the setting on the provider form, only with the daily refresh.
   */
  public function testSetting(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $provider = Provider::create(['id' => 'reg', 'label' => 'Reg', 'plugin' => 'test_registrar']);
    $provider->save();
    $form = $this->container->get('entity.form_builder')->getForm($provider, 'edit');
    $this->assertArrayHasKey('add_new_domains', $form);

    $form_state = (new FormState())->setValues(['add_new_domains' => TRUE, 'registration_sync' => TRUE]);
    ImportHooks::buildProvider('commerce_domains_provider', $provider, $form, $form_state);
    $this->assertTrue(AccountImport::addsNew($provider));
    $form_state = (new FormState())->setValues(['add_new_domains' => TRUE, 'registration_sync' => FALSE]);
    ImportHooks::buildProvider('commerce_domains_provider', $provider, $form, $form_state);
    $this->assertFalse(AccountImport::addsNew($provider), 'Only beside the daily refresh.');
  }

}

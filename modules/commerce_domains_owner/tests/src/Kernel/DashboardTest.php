<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_marketplace\ListingRecorder;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_owner\Checklist;
use Drupal\commerce_domains_owner\Controller\DashboardController;
use Drupal\commerce_domains_owner\Controller\ProfitController;
use Drupal\commerce_domains_owner\EventSubscriber\LanderSubscriber;
use Drupal\commerce_domains_owner\Hook\NavigationHooks;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Tests the dashboard, its next steps, and where it sits in the admin.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(ProfitController::class)]
#[CoversClass(Checklist::class)]
#[CoversClass(LanderSubscriber::class)]
#[CoversClass(NavigationHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DashboardTest extends OwnerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'advancedqueue',
    'commerce_domains_marketplace',
    'commerce_cart',
    'commerce_checkout',
    'commerce_domains_offer',
    'commerce_payment',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installSchema('commerce_domains_marketplace', [ListingRecorder::TABLE]);
    $this->installEntitySchema('commerce_domains_offer');
    // The sync queue and the domain overview's views.
    $this->installConfig(['commerce_domains_marketplace', 'commerce_checkout', 'commerce_domains_offer']);
  }

  /**
   * Tests who may see the owner pages.
   */
  public function testAccess(): void {
    $access = fn(string $route, array $permissions) => $this->container->get('access_manager')->checkNamedRoute($route, [], $this->createUser($permissions));
    foreach (['dashboard', 'expiring', 'profit'] as $page) {
      $this->assertFalse($access("commerce_domains_owner.$page", []), $page);
      $this->assertTrue($access("commerce_domains_owner.$page", ['view domain portfolio']), $page);
      $this->assertTrue($access("commerce_domains_owner.$page", ['manage domain portfolio']), $page);
    }
    $this->assertFalse($access('commerce_domains_owner.settings', ['view domain portfolio']));
    $this->assertTrue($access('commerce_domains_owner.settings', ['manage domain portfolio']));
  }

  /**
   * Tests the next steps, as the site's state completes them.
   */
  public function testChecklist(): void {
    $checklist = $this->container->get(Checklist::class);
    $done = fn() => array_map(fn(array $step) => $step['done'], $checklist->steps());
    $steps = $done();
    $this->assertSame([
      'registrar' => FALSE,
      'domains' => FALSE,
      'marketplace' => FALSE,
      'payment' => FALSE,
      'lander' => FALSE,
      'notify' => FALSE,
    ], $steps);

    // A registrar without an API counts; a marketplace without its keys,
    // or a disabled one, doesn't.
    $this->createProvider('hover', NULL);
    $this->createDomain('First.com');
    $this->createProvider('afternic', 'afternic');
    $this->createProvider('off', 'test_marketplace', FALSE);
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
    $steps = $done();
    $this->assertTrue($steps['registrar']);
    $this->assertTrue($steps['domains']);
    $this->assertFalse($steps['marketplace']);
    $this->assertTrue($steps['payment']);

    $this->createProvider('market', 'test_marketplace');
    // A visitor comes through the lander, on a path of the site's own.
    $visit = function (string $path, string $controller): void {
      $request = Request::create($path);
      $request->attributes->set('_controller', $controller);
      $event = new TerminateEvent($this->container->get('http_kernel'), $request, new Response());
      $this->container->get(LanderSubscriber::class)->onTerminate($event);
    };
    $visit('/domains', '\Drupal\views\Routing\ViewPageController::handle');
    $this->assertNull($this->container->get('state')->get(Checklist::LANDER_KEY));
    $visit('/system/redirect-landing', '\Drupal\commerce_domains\Controller\LanderController::redirect');
    $this->assertSame($this->now, $this->container->get('state')->get(Checklist::LANDER_KEY));
    $this->config('commerce_domains_owner.settings')->set('notify_mail', 'owner@example.com')->save();
    $steps = $done();
    $this->assertSame([TRUE], array_values(array_unique($steps)));

    $build = $this->container->get('class_resolver')->getInstanceFromDefinition(DashboardController::class)->build();
    $this->assertSame('Next steps: all done', (string) $build['checklist']['#title']);
    $this->assertFalse($build['checklist']['#open']);
  }

  /**
   * Tests the dashboard's sections, built without calling any API.
   */
  public function testDashboard(): void {
    $state = $this->container->get('state');
    $registrar = $this->createProvider('reg');
    $registrar->setRegistrationSync(TRUE)->save();
    $market = $this->createProvider('afternic', 'test_marketplace');
    $market->setThirdPartySetting('commerce_domains_marketplace', 'auto_sync', TRUE)->save();
    $this->createDomain('Sold.com', 1000, [
      'domain_purchase_price' => new Price('100', 'USD'),
      'domain_sale_price' => new Price('900', 'USD'),
      'domain_sold' => $this->now - 3600,
      'domain_sold_via' => 'afternic',
    ]);
    $expiring = $this->createDomain('Expiring.com', 1000, [
      'domain_registrar' => 'reg',
      'domain_expires' => $this->now + 10 * self::DAY,
    ]);
    $this->createDomain('Missing.com', 1000, ['domain_registrar' => 'reg']);
    $state->set('commerce_domains_test.registrations', ['expiring.com' => $this->now + 10 * self::DAY]);
    $this->container->get(RegistrarSync::class)->plan($registrar);
    $state->delete('commerce_domains_test.registrar_calls');

    $database = $this->container->get('database');
    foreach (['sold.com' => ListingRecorder::FAILED, 'expiring.com' => ListingRecorder::OUTDATED] as $domain => $status) {
      $database->insert(ListingRecorder::TABLE)->fields([
        'provider' => 'afternic',
        'domain' => $domain,
        'status' => $status,
        'checked' => $this->now - 100,
        'pushed' => $this->now - 200,
        'changed' => $this->now - 100,
      ])->execute();
    }
    $this->container->get(Notifier::class)->syncFailed($market, ['' => 'Afternic is down.']);
    $variations = $expiring->getVariations();
    $buyer = ['mail' => 'buyer@example.com', 'name' => 'Ana'];
    $this->container->get(OfferManager::class)->submit(reset($variations), new Price('700', 'USD'), $buyer, new AnonymousUserSession());

    $build = $this->container->get('class_resolver')->getInstanceFromDefinition(DashboardController::class)->build();
    $this->assertSame(0, $state->get('commerce_domains_test.registrar_calls', 0), 'No registrar was asked.');
    $this->assertSame([], $state->get('commerce_domains_test.calls', []), 'No marketplace was asked.');
    $this->assertSame(0, $build['#cache']['max-age']);

    $this->assertSame('Offers: 1 waits for an answer', (string) $build['offers']['#title']);
    $this->assertSame('$700.00', $build['offers']['table']['#rows'][0][1]);
    $this->assertCount(1, $build['sales']['table']['#rows']);
    $this->assertSame('Expiring within 60 days: 1 domain', (string) $build['expiring']['#title']);
    $this->assertSame('Not decided', (string) $build['expiring']['table']['#rows'][0][2]);

    $row = $build['marketplaces']['table']['#rows']['afternic'];
    $this->assertSame('Automatic', (string) $row[1]);
    $this->assertSame([1, 1], [$row[4], $row[5]], 'One listing out of date, one refused.');
    $this->assertStringContainsString('Afternic is down.', (string) $row[6]);

    $row = $build['registrars']['table']['#rows']['reg'];
    $this->assertSame('Daily', (string) $row[1]);
    $this->assertSame('1 domain', (string) $row[3]['data']['#title']);

    // It renders, and so does the profit report.
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    foreach (['Sold.com', 'Expiring.com', 'Afternic is down.', 'Next steps'] as $text) {
      $this->assertStringContainsString($text, $html);
    }
    $report = $this->container->get('class_resolver')->getInstanceFromDefinition(ProfitController::class)->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($report);
    $this->assertStringContainsString('$800.00', $html);
    $this->assertStringContainsString('Renewals paid is the yearly renewal cost', $html);
  }

  /**
   * Tests the menu link and tabs, with and without the domain overview.
   */
  public function testNavigation(): void {
    $rebuild = function (): array {
      $this->container->get('router.builder')->rebuild();
      $links = $this->container->get('plugin.manager.menu.link');
      $links->rebuild();
      $tasks = $this->container->get('plugin.manager.menu.local_task');
      $tasks->clearCachedDefinitions();
      return [
        $links->getDefinition('commerce_domains_owner.dashboard')['parent'],
        $tasks->getDefinition('commerce_domains_owner.expiring')['base_route'],
      ];
    };
    $this->assertSame(['views_view:views.commerce_domains_overview.page_1', 'view.commerce_domains_overview.page_1'], $rebuild());
    View::load('commerce_domains_listings')->delete();
    View::load('commerce_domains_overview')->delete();
    $this->assertSame(['commerce.admin_commerce', 'commerce_domains_owner.dashboard'], $rebuild());
  }

}

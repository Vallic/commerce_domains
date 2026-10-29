<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Controller\LanderController;
use Drupal\commerce_domains\Hook\TokenHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the lander redirect and the price tokens of domain pages.
 */
#[CoversClass(LanderController::class)]
#[CoversClass(TokenHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainPagesTest extends DomainsKernelTestBase {

  /**
   * Tests sending parked domains' visitors to their page.
   */
  public function testLander(): void {
    $product = $this->createDomain('Parked.com');
    $this->createDomain('Gone.com', 1000, ['status' => FALSE]);
    $controller = LanderController::create($this->container);
    $url = $product->toUrl()->toString();

    $request = Request::create('/domain-lander', 'GET', [], [], [], ['HTTP_REFERER' => 'http://www.parked.com/some/page']);
    $response = $controller->redirect($request);
    $this->assertSame($url, $response->getTargetUrl());
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame($url, $controller->redirect(Request::create('/domain-lander', 'GET', ['domain' => 'PARKED.com']))->getTargetUrl());

    // Unpublished, unknown or missing: the fallback.
    foreach (['http://gone.com/', 'http://other.com/', ''] as $referer) {
      $request = Request::create('/domain-lander', 'GET', [], [], [], ['HTTP_REFERER' => $referer]);
      $this->assertSame('/domains', $controller->redirect($request, '/domains')->getTargetUrl(), $referer);
    }
  }

  /**
   * Tests the price tokens.
   */
  public function testTokens(): void {
    // The default variation is the first the visitor may see.
    $this->setUpCurrentUser([], ['view commerce_product']);
    $product = $this->createDomain('Token.com', 1250);
    $text = $this->container->get('token')->replace('[commerce_product:current_price] [commerce_product:current_currency]', ['commerce_product' => $product]);
    $this->assertSame('1250 USD', $text);
  }

}

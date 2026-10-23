<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\NamecheapClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Namecheap;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Namecheap provider.
 */
#[CoversClass(Namecheap::class)]
#[CoversClass(NamecheapClient::class)]
#[Group('commerce_domains')]
class NamecheapTest extends ProviderTestBase {

  protected const KEYS = [
    'api_user' => 'acre',
    'api_key' => 'NcKey42',
    'client_ip' => '203.0.113.7',
    'requests_per_minute' => 60000,
  ];

  /**
   * Builds an XML response.
   */
  protected static function xml(string $body, string $status = 'OK'): Response {
    return new Response(200, ['Content-Type' => 'text/xml'], <<<XML
      <?xml version="1.0" encoding="utf-8"?>
      <ApiResponse Status="$status" xmlns="http://api.namecheap.com/xml.response">
        $body
        <Server>SERVER-NAME</Server>
      </ApiResponse>
      XML);
  }

  /**
   * Builds a getList page.
   */
  protected static function page(string $domains, int $total): Response {
    return self::xml(<<<XML
      <Errors />
      <CommandResponse Type="namecheap.domains.getList">
        <DomainGetListResult>$domains</DomainGetListResult>
        <Paging><TotalItems>$total</TotalItems><CurrentPage>1</CurrentPage><PageSize>100</PageSize></Paging>
      </CommandResponse>
      XML);
  }

  /**
   * Tests reading the account page by page.
   */
  public function testFetchRegistrations(): void {
    $full = implode('', array_map(fn($i) => "<Domain ID=\"$i\" Name=\"d$i.com\" />", range(1, 100)));
    $plugin = $this->registrar(Namecheap::class, 'namecheap', self::KEYS, [
      self::page($full, 102),
      self::page(<<<XML
        <Domain ID="127" Name="A.com" Created="02/15/2016" Expires="02/15/2027" IsExpired="false" IsLocked="true" AutoRenew="false" />
        <Domain ID="381" Name="b.com" Expires="04/28/2023" IsExpired="true" IsLocked="false" AutoRenew="true" />
        XML, 102),
    ]);
    $registrations = $plugin->fetchRegistrations();
    $this->assertCount(102, $registrations);
    $this->assertEquals(new Registration('a.com', 1802649600, TRUE, FALSE), $registrations['a.com']);
    $this->assertSame('expired', $registrations['b.com']->status);
    $this->assertTrue($registrations['b.com']->autoRenew);

    $this->assertSame('GET https://api.namecheap.com/xml.response', $this->call(0));
    $this->assertSame([
      'ApiUser' => 'acre',
      'ApiKey' => 'NcKey42',
      'UserName' => 'acre',
      'ClientIp' => '203.0.113.7',
      'Command' => 'namecheap.domains.getList',
      'Page' => '1',
      'PageSize' => '100',
    ], $this->query(0));
    $this->assertSame('2', $this->query(1)['Page']);
  }

  /**
   * Tests that a refused key is retryable and kept out of the message.
   */
  public function testError(): void {
    $plugin = $this->registrar(Namecheap::class, 'namecheap', self::KEYS, [
      self::xml('<Errors><Error Number="1011102">API Key NcKey42 is invalid or API access has not been enabled</Error></Errors>', 'ERROR'),
      self::xml('<Errors><Error Number="5050169">Unknown exceptions</Error></Errors>', 'ERROR'),
    ]);
    try {
      $plugin->fetchRegistrations();
      $this->fail('The error is thrown.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Namecheap namecheap.domains.getList: API Key *** is invalid or API access has not been enabled (error 1011102)', $e->getMessage());
      $this->assertTrue($e->retryable);
    }
    try {
      $plugin->fetchRegistrations();
      $this->fail('The error is thrown.');
    }
    catch (ProviderException $e) {
      $this->assertFalse($e->retryable, 'Command errors need a look.');
    }
  }

}

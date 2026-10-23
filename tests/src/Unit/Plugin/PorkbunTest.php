<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Porkbun;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Porkbun provider.
 */
#[CoversClass(Porkbun::class)]
#[CoversClass(PorkbunClient::class)]
#[Group('commerce_domains')]
class PorkbunTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'pk1_abc', 'secret_key' => 'sk1_Secret9', 'requests_per_minute' => 60000];

  /**
   * Tests reading the account 1,000 domains at a time.
   */
  public function testFetchRegistrations(): void {
    $full = array_map(fn($i) => ['domain' => "d$i.com"], range(1, 1000));
    $plugin = $this->registrar(Porkbun::class, 'porkbun', self::KEYS, [
      self::json(['status' => 'SUCCESS', 'count' => 1000, 'domains' => $full]),
      self::json([
        'status' => 'SUCCESS',
        'count' => 1,
        'domains' => [
          [
            'domain' => 'A.com',
            'status' => 'ACTIVE',
            'expireDate' => '2027-01-15 10:00:00',
            'securityLock' => 1,
            'autoRenew' => '0',
          ],
        ],
      ]),
    ]);
    $registrations = $plugin->fetchRegistrations();
    $this->assertCount(1001, $registrations);
    $this->assertEquals(new Registration('a.com', 1800007200, TRUE, FALSE, 'active'), $registrations['a.com']);
    $this->assertSame('POST https://api.porkbun.com/api/json/v3/domain/listAll', $this->call(0));
    $this->assertSame(['start' => 0], $this->body(0));
    $this->assertSame(['start' => 1000], $this->body(1));
    $this->assertSame('pk1_abc', $this->header(0, 'X-API-Key'));
    $this->assertSame('sk1_Secret9', $this->header(0, 'X-Secret-API-Key'));
  }

  /**
   * Tests fetching a few names, and errors reported in the body.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(Porkbun::class, 'porkbun', self::KEYS, [
      self::json(['status' => 'SUCCESS', 'domain' => ['domain' => 'a.com', 'securityLock' => 0]]),
      self::json(['status' => 'ERROR', 'code' => 'DOMAIN_NOT_FOUND', 'message' => 'Not found.'], 404),
      self::json(['status' => 'ERROR', 'code' => 'API_ACCESS_DISABLED', 'message' => 'Opt in sk1_Secret9 first.']),
    ]);
    $expected = ['a.com' => new Registration('a.com', NULL, FALSE)];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $this->assertSame('GET https://api.porkbun.com/api/json/v3/domain/get/gone.com', $this->call(1));

    try {
      $plugin->fetchRegistrations(['b.com']);
      $this->fail('An error status fails the call.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Porkbun GET domain/get/b.com: HTTP 200: Opt in *** first. (API_ACCESS_DISABLED)', $e->getMessage());
      $this->assertFalse($e->retryable);
    }
  }

}

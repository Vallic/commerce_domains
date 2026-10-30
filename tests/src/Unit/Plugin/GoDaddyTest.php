<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\GoDaddyClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\GoDaddy;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the GoDaddy provider.
 */
#[CoversClass(GoDaddy::class)]
#[CoversClass(GoDaddyClient::class)]
#[Group('commerce_domains')]
class GoDaddyTest extends ProviderTestBase {

  protected const KEYS = ['api_token' => 'gd-Tok-1', 'requests_per_minute' => 60000];

  /**
   * Tests reading the account page by page.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      self::json([
        'items' => [
          [
            'domain' => 'A.com',
            'createdAt' => '2016-07-08T09:10:11Z',
            'expiresAt' => '2027-06-12T10:02:10Z',
            'autoRenew' => TRUE,
            'transferLock' => TRUE,
            'status' => 'ACTIVE',
          ],
        ],
        'links' => [['rel' => 'next', 'href' => '/v3/domains/domain-names?pageSize=200&pageToken=abc']],
      ]),
      self::json([
        'items' => [['domain' => 'b.com', 'status' => 'EXPIRED']],
        'links' => [['rel' => 'self', 'href' => '/v3/x']],
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1812794530, TRUE, TRUE, 'active', registered: 1467969011),
      'b.com' => new Registration('b.com', NULL, NULL, NULL, 'expired'),
    ], $plugin->fetchRegistrations());
    $this->assertSame('GET https://api.godaddy.com/v3/domains/domain-names', $this->call(0));
    $this->assertSame('Bearer gd-Tok-1', $this->header(0, 'Authorization'));
    $this->assertSame(['pageSize' => '200', 'pageToken' => 'abc'], $this->query(1));
  }

  /**
   * Tests that a next link to another host is not followed.
   */
  public function testForeignNextLink(): void {
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      self::json([
        'items' => [['domain' => 'a.com']],
        'links' => [['rel' => 'next', 'href' => 'https://evil.example/v3/domains/domain-names']],
      ]),
    ]);
    $this->assertCount(1, $plugin->fetchRegistrations());
    $this->assertCount(1, $this->history, 'The token is never sent elsewhere.');
  }

  /**
   * Tests fetching a few names one by one.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(GoDaddy::class, 'godaddy', self::KEYS, [
      self::json(['domain' => 'a.com', 'transferLock' => FALSE]),
      self::json(['name' => 'NOT_FOUND', 'message' => 'Not found'], 404),
    ]);
    $expected = ['a.com' => new Registration('a.com', NULL, FALSE)];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $this->assertSame('GET https://api.godaddy.com/v3/domains/domain-names/gone.com', $this->call(1));
  }

}

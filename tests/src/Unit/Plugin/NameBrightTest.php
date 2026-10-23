<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\NameBrightClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\NameBright;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the NameBright provider.
 */
#[CoversClass(NameBright::class)]
#[CoversClass(NameBrightClient::class)]
#[Group('commerce_domains')]
class NameBrightTest extends ProviderTestBase {

  protected const KEYS = ['client_id' => 'nb-app', 'client_secret' => 'Nb-secret-3', 'requests_per_minute' => 60000];

  /**
   * Tests the token, then the account list.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(NameBright::class, 'namebright', self::KEYS, [
      self::json(['access_token' => 'nb-tok', 'token_type' => 'Bearer', 'expires_in' => 3600]),
      self::json([
        'domains' => [
          [
            'domainName' => 'A.com',
            'status' => 'Active',
            'expirationDate' => '2027-09-09T09:09:09Z',
            'locked' => TRUE,
            'autoRenew' => FALSE,
          ],
          ['domainName' => 'b.com'],
        ],
        'resultsTotal' => 2,
        'currentPage' => 1,
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1820480949, TRUE, FALSE, 'Active'),
      'b.com' => new Registration('b.com', NULL),
    ], $plugin->fetchRegistrations());
    $this->assertSame('POST https://api.namebright.com/auth/token', $this->call(0));
    parse_str((string) $this->history[0]['request']->getBody(), $form);
    $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'nb-app', 'client_secret' => 'Nb-secret-3'], $form);
    $this->assertSame('GET https://api.namebright.com/domains', $this->call(1));
    $this->assertSame(['page' => '1', 'size' => '1000'], $this->query(1));
    $this->assertSame('Bearer nb-tok', $this->header(1, 'Authorization'));
  }

  /**
   * Tests fetching a few names one by one.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(NameBright::class, 'namebright', self::KEYS, [
      self::json(['access_token' => 'nb-tok']),
      self::json(['domainName' => 'a.com', 'locked' => FALSE]),
      self::json(['title' => 'Not Found', 'status' => 404], 404),
    ]);
    $expected = ['a.com' => new Registration('a.com', NULL, FALSE)];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $this->assertSame('GET https://api.namebright.com/domains/gone.com', $this->call(2));
  }

}

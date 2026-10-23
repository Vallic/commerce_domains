<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\IonosClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Ionos;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the IONOS provider.
 */
#[CoversClass(Ionos::class)]
#[CoversClass(IonosClient::class)]
#[Group('commerce_domains')]
class IonosTest extends ProviderTestBase {

  protected const KEYS = ['api_key' => 'pre.Secret5', 'requests_per_minute' => 60000];

  /**
   * Returns an account list with an active, an expiring and a new domain.
   */
  protected static function account(): array {
    return [
      'count' => 3,
      'domains' => [
        [
          'id' => 'id-1',
          'name' => 'a.com',
          'status' => [
            'provisioningStatus' => [
              'type' => 'ACTIVE',
              'setToExpireOn' => '2027-04-02T07:17:45.862Z',
              'setToRenewOn' => '2027-03-01T00:00:00Z',
            ],
          ],
        ],
        ['id' => 'id-2', 'name' => 'b.de', 'status' => ['provisioningStatus' => ['type' => 'EXPIRING']]],
        ['id' => 'id-3', 'name' => 'new.com', 'pendingProvisioning' => TRUE],
      ],
    ];
  }

  /**
   * Tests the list, with details only for domains it has no expiry for.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(Ionos::class, 'ionos', self::KEYS, [
      self::json(self::account()),
      self::json([
        'id' => 'id-2',
        'name' => 'b.de',
        'expirationDate' => '2026-12-01T00:00:00Z',
        'autoRenew' => FALSE,
        'transferLock' => TRUE,
        'domainLock' => FALSE,
        'status' => ['provisioningStatus' => ['type' => 'EXPIRING']],
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1806650265, NULL, TRUE, 'active'),
      'b.de' => new Registration('b.de', 1796083200, TRUE, FALSE, 'expiring'),
      'new.com' => new Registration('new.com', NULL),
    ], $plugin->fetchRegistrations());
    $this->assertSame('GET https://api.hosting.ionos.com/domains/v1/domainitems', $this->call(0));
    $this->assertSame(['offset' => '0', 'limit' => '100', 'includeDomainStatus' => 'true'], $this->query(0));
    $this->assertSame('pre.Secret5', $this->header(0, 'X-API-Key'));
    $this->assertSame('GET https://api.hosting.ionos.com/domains/v1/domainitems/id-2', $this->call(1));
    $this->assertCount(2, $this->history);
  }

  /**
   * Tests that a few names get their details, and errors come as a list.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(Ionos::class, 'ionos', self::KEYS, [
      self::json(self::account()),
      self::json([
        'id' => 'id-1',
        'name' => 'a.com',
        'autoRenew' => TRUE,
        'cancellationDate' => '2027-01-01T00:00:00Z',
        'transferLock' => FALSE,
      ]),
      self::json([['code' => 'UNAUTHORIZED', 'message' => 'Missing or invalid API key.']], 401),
    ]);
    $registrations = $plugin->fetchRegistrations(['a.com']);
    $this->assertEquals(['a.com' => new Registration('a.com', NULL, FALSE, FALSE)], $registrations, 'A cancellation date means no renewal.');
    $this->assertSame('GET https://api.hosting.ionos.com/domains/v1/domainitems/id-1', $this->call(1));

    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('IONOS GET v1/domainitems: HTTP 401: Missing or invalid API key.');
    $plugin->fetchRegistrations();
  }

  /**
   * Tests the rate setting every plugin with a documented limit gets.
   */
  public function testRateSetting(): void {
    $plugin = $this->registrar(Ionos::class, 'ionos');
    $this->assertSame(20, $plugin->getConfiguration()['requests_per_minute']);
    $form = $plugin->buildConfigurationForm([], new FormState());
    $this->assertSame(20, $form['requests_per_minute']['#default_value']);
    $plugin->submitConfigurationForm($form, (new FormState())->setValues(['requests_per_minute' => '10']));
    $this->assertSame(10, $plugin->getConfiguration()['requests_per_minute']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\SpaceshipClient;
use Drupal\commerce_domains\ProviderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Spaceship SellerHub client.
 */
#[CoversClass(SpaceshipClient::class)]
#[Group('commerce_domains')]
class SpaceshipClientTest extends ClientTestBase {

  /**
   * Returns a client answering with the given responses.
   */
  protected function client(array $responses): SpaceshipClient {
    return new SpaceshipClient($this->http($responses), 'SPKEY', 'Sp-secret-9');
  }

  /**
   * Tests paging, single lookups and writes.
   */
  public function testCalls(): void {
    $page = fn(int $from, int $count) => array_map(fn($i) => ['name' => "p$i.com", 'status' => 'onSale'], range($from, $from + $count - 1));
    $client = $this->client([
      self::json(['items' => $page(0, 100), 'total' => 130]),
      self::json(['items' => $page(100, 30), 'total' => 130]),
      self::json(['name' => 'a.com', 'status' => 'onSale']),
      self::json(['detail' => 'Not found'], 404),
      self::json(['name' => 'b.com'], 201),
      self::json(['name' => 'a.com']),
      self::json(NULL, 204),
      self::json(['detail' => 'Not found'], 404),
    ]);

    $this->assertCount(130, $client->listDomains());
    $this->assertSame(['take' => '100', 'skip' => '100'], $this->query(1));
    $this->assertSame('SPKEY', $this->header(0, 'X-Api-Key'));
    $this->assertSame('Sp-secret-9', $this->header(0, 'X-Api-Secret'));

    $this->assertSame(['name' => 'a.com', 'status' => 'onSale'], $client->getDomain('a.com'));
    $this->assertSame('GET https://spaceship.dev/api/v1/sellerhub/domains/a.com', $this->call(2));
    $this->assertNull($client->getDomain('missing.com'));

    $client->createDomain(['name' => 'b.com', 'binPriceEnabled' => TRUE]);
    $this->assertSame('POST https://spaceship.dev/api/v1/sellerhub/domains', $this->call(4));
    $this->assertSame(['name' => 'b.com', 'binPriceEnabled' => TRUE], $this->body(4));

    $client->updateDomain('a.com', ['binPrice' => ['amount' => '10', 'currency' => 'USD']]);
    $this->assertSame('PATCH https://spaceship.dev/api/v1/sellerhub/domains/a.com', $this->call(5));

    $client->deleteDomain('a.com');
    // Already gone is fine.
    $client->deleteDomain('gone.com');
    $this->assertSame('DELETE https://spaceship.dev/api/v1/sellerhub/domains/gone.com', $this->call(7));
  }

  /**
   * Tests that validation errors name the field and the operation.
   */
  public function testValidationError(): void {
    $client = $this->client([self::json(
      ['detail' => 'Validation failed', 'data' => [['field' => 'minPrice', 'details' => 'must be lower than binPrice']]],
      422,
      ['spaceship-error-code' => 'business.validation', 'spaceship-operation-id' => 'abcdefgh12345678'],
      ),
    ]);
    try {
      $client->createDomain(['name' => 'b.com']);
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertFalse($e->retryable);
      $this->assertSame('Spaceship POST domains: HTTP 422: Validation failed (minPrice must be lower than binPrice) [operation abcdefgh12345678]', $e->getMessage());
    }
  }

}

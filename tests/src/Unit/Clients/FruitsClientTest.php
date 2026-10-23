<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\FruitsClient;
use Drupal\commerce_domains\ProviderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the fruits API client.
 */
#[CoversClass(FruitsClient::class)]
#[Group('commerce_domains')]
class FruitsClientTest extends ClientTestBase {

  /**
   * Returns a client answering with the given responses.
   */
  protected function client(array $responses): FruitsClient {
    return new FruitsClient($this->http($responses), 'fr-client', 'Fr-secret-5');
  }

  /**
   * Tests the token request and reusing the token.
   */
  public function testToken(): void {
    $client = $this->client([
      self::json(['access_token' => 'tok-1', 'expires_in' => 7200]),
      self::json(['items' => [['name' => 'a.com']], 'pagination' => ['limit' => 1000, 'offset' => 0, 'total' => 1]]),
      self::json(['name' => 'a.com']),
    ]);
    $this->assertCount(1, $client->listDomains());
    $this->assertSame(['name' => 'a.com'], $client->getDomain('a.com'));

    $this->assertSame('POST https://api.fruits.co/oauth2/token', $this->call(0));
    // Fruits refuses Basic auth: the credentials go in the body.
    $this->assertSame('', $this->header(0, 'Authorization'));
    parse_str((string) $this->history[0]['request']->getBody(), $form);
    $this->assertSame([
      'grant_type' => 'client_credentials',
      'client_id' => 'fr-client',
      'client_secret' => 'Fr-secret-5',
      'scope' => 'content.domain.read content.domain.write',
    ], $form);
    $this->assertSame('Bearer tok-1', $this->header(1, 'Authorization'));
    $this->assertSame(['limit' => '1000', 'offset' => '0'], $this->query(1));
    $this->assertSame('Bearer tok-1', $this->header(2, 'Authorization'), 'The token is reused.');
    $this->assertCount(3, $this->history);
  }

  /**
   * Tests that rejected credentials are retryable and redacted.
   */
  public function testRejectedCredentials(): void {
    $client = $this->client([
      self::json(['error' => 'invalid_client', 'error_description' => 'Client Fr-secret-5 failed'], 401),
    ]);
    try {
      $client->getDomain('a.com');
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertSame('fruits POST oauth2/token: HTTP 401: Client *** failed', $e->getMessage());
    }
  }

  /**
   * Tests a token response without a token.
   */
  public function testMissingToken(): void {
    $client = $this->client([self::json(['token_type' => 'bearer'])]);
    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('fruits returned no access token.');
    $client->getDomain('a.com');
  }

  /**
   * Tests imports, waiting for them, and deletes.
   */
  public function testImportAndDelete(): void {
    $client = $this->client([
      self::json(['access_token' => 'tok-1', 'expires_in' => 7200]),
      self::json(['id' => 'imp-1', 'state' => 'completed'], 201),
      self::json(['id' => 'imp-2', 'state' => 'failed'], 201),
      self::json(['message' => 'not found', 'code' => 404], 404),
      self::json([
        'message' => 'Unprocessable',
        'code' => 422,
        'fields' => ['price' => ['field' => 'price', 'message' => 'must be positive']],
      ], 422),
    ]);
    $import = $client->importDomains([['name' => 'a.com', 'price' => 100, 'currency' => 'eur']]);
    $this->assertSame([
      'mode' => 'add_update',
      'source' => [
        'type' => 'items',
        'items' => [['name' => 'a.com', 'price' => 100, 'currency' => 'eur']],
      ],
    ], $this->body(1));
    // Finished imports need no polling.
    $this->assertSame('completed', $client->waitForImport($import)['state']);
    $this->assertSame('failed', $client->waitForImport($client->importDomains([['name' => 'b.com']]))['state']);
    $this->assertCount(3, $this->history);

    $client->deleteDomain('gone.com');
    $this->assertSame('DELETE https://api.fruits.co/v1/domains/gone.com', $this->call(3));
    try {
      $client->deleteDomain('bad.com');
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertSame('fruits DELETE v1/domains/bad.com: HTTP 422: Unprocessable (price must be positive)', $e->getMessage());
    }
  }

}

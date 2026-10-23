<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\AfternicClient;
use Drupal\commerce_domains\ProviderException;
use Drupal\Component\Uuid\Php;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Afternic Portfolio API client.
 */
#[CoversClass(AfternicClient::class)]
#[Group('commerce_domains')]
class AfternicClientTest extends ClientTestBase {

  /**
   * Returns a client answering with the given responses.
   */
  protected function client(array $responses): AfternicClient {
    return new AfternicClient($this->http($responses), new Php(), 'KEY', 'Af-secret-1');
  }

  /**
   * Tests paging through the portfolio.
   */
  public function testListDomains(): void {
    $page = fn(int $from, int $count) => array_map(fn($i) => ['name' => "p$i.com"], range($from, $from + $count - 1));
    $client = $this->client([
      self::json(['domains' => $page(0, 100), 'pagination' => ['total' => 103]]),
      self::json(['domains' => $page(100, 3), 'pagination' => ['total' => 103]]),
    ]);
    $this->assertCount(103, $client->listDomains());
    $this->assertSame('GET https://api.afternic.com/v3/aftermarket/customers/my/domains', $this->call(0));
    $this->assertSame(['limit' => '100', 'offset' => '100', 'order' => 'NAME_ASC'], $this->query(1));
    $this->assertSame('sso-key KEY:Af-secret-1', $this->header(0, 'Authorization'));
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->header(0, 'X-Request-Id'));
  }

  /**
   * Tests fetching, upserting and deleting in batches.
   */
  public function testBatchCalls(): void {
    $client = $this->client([
      self::json(['domains' => [['name' => 'a.com']]]),
      self::json([
        'a.com' => ['code' => 200, 'domain' => []],
        'b.com' => [
          'code' => 422,
          'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Bad',
            'fields' => [['path' => 'minbidPrice', 'message' => 'too low']],
          ],
        ],
      ]),
      self::json(['a.com' => ['code' => 200], 'gone.com' => ['code' => 404]]),
    ]);

    $this->assertSame([['name' => 'a.com']], $client->getDomains(['a.com', 'b.com']));
    $this->assertSame('POST https://api.afternic.com/v3/aftermarket/customers/my/domains/$each/get', $this->call(0));
    $this->assertSame(['names' => ['a.com', 'b.com']], $this->body(0));

    $errors = $client->upsertDomains([['name' => 'a.com'], ['name' => 'b.com'], ['name' => 'c.com']]);
    $this->assertSame([
      'b.com' => 'VALIDATION_ERROR: Bad (minbidPrice too low)',
      'c.com' => 'Afternic returned no result for this domain.',
    ], $errors);

    // A name that is already gone is what a delete wants.
    $this->assertSame([], $client->deleteDomains(['a.com', 'gone.com']));
    $this->assertSame('POST https://api.afternic.com/v3/aftermarket/customers/my/domains/$each/delete', $this->call(2));
  }

  /**
   * Tests that whole-request errors quote the request ID, not the secret.
   */
  public function testErrors(): void {
    $client = $this->client([self::json(['code' => 'AUTH_CODE_INVALID', 'message' => 'Key KEY:Af-secret-1 invalid'], 401)]);
    try {
      $client->getDomains(['a.com']);
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertStringContainsString('AUTH_CODE_INVALID: Key KEY:*** invalid', $e->getMessage());
      $this->assertStringContainsString('[request ' . $this->header(0, 'X-Request-Id') . ']', $e->getMessage());
    }
  }

}

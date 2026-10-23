<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\AtomClient;
use Drupal\commerce_domains\ProviderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Atom Seller API client.
 */
#[CoversClass(AtomClient::class)]
#[Group('commerce_domains')]
class AtomClientTest extends ClientTestBase {

  /**
   * Returns a client answering with the given responses.
   */
  protected function client(array $responses): AtomClient {
    return new AtomClient($this->http($responses), 'atomTOKEN/123', '4242');
  }

  /**
   * Tests reading lists in the envelopes the API may use.
   */
  #[DataProvider('providerEnvelopes')]
  public function testListEnvelopes(mixed $body, int $count): void {
    $client = $this->client([self::json($body)]);
    $this->assertCount($count, $client->listDomains('standard'));
    $expected = [
      'api_token' => 'atomTOKEN/123',
      'user_id' => '4242',
      'type' => 'standard',
      'page' => '1',
      'limit' => '100',
    ];
    $this->assertSame($expected, $this->query(0));
  }

  /**
   * Data provider for testListEnvelopes().
   */
  public static function providerEnvelopes(): array {
    $rows = [['domain_name' => 'A.com', 'selling_price' => 100], ['domain_name' => 'B.com', 'selling_price' => 200]];
    return [
      'bare list' => [$rows, 2],
      'data key' => [['success' => TRUE, 'data' => $rows], 2],
      'nested' => [['data' => ['domains' => $rows]], 2],
      'empty list' => [[], 0],
      'empty data' => [['data' => []], 0],
      'message only' => [['message' => 'No domains found'], 0],
    ];
  }

  /**
   * Tests stopping when the API ignores the page parameter.
   */
  public function testRepeatedPageStopsPaging(): void {
    $rows = array_map(fn($i) => ['domain_name' => "d$i.com"], range(1, 100));
    $client = $this->client([self::json($rows), self::json($rows)]);
    $this->assertCount(100, $client->listDomains('premium'));
    $this->assertCount(2, $this->history);
  }

  /**
   * Tests that a list failure reported with HTTP 200 throws.
   */
  public function testListFailure(): void {
    $client = $this->client([self::json(['success' => FALSE, 'message' => 'Invalid API token'])]);
    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('Atom GET domains: Invalid API token');
    $client->listDomains('standard');
  }

  /**
   * Tests the writes and how failures reported with HTTP 200 are read.
   */
  public function testWrites(): void {
    $client = $this->client([
      self::json(['success' => TRUE]),
      self::json(['status' => 'Approved']),
      self::json(['status' => 'success']),
      self::json(['success' => FALSE, 'message' => 'Domain already exists']),
      self::json(['status' => 'error', 'error' => 'Not your domain']),
      self::json(['error' => ['code' => 7]]),
    ]);
    $client->addDomain('a.com', 1200);
    $this->assertSame('GET https://www.atom.com/api/marketplace/add-domain', $this->call(0));
    $query = array_diff_key($this->query(0), ['api_token' => 1, 'user_id' => 1]);
    $this->assertSame(['domain_type' => 'standard', 'domain_name' => 'a.com', 'price' => '1200'], $query);
    $client->updateSellingPrice('a.com', 1300);
    $this->assertSame('1300', $this->query(1)['selling_price']);
    $client->removeDomain('a.com');

    foreach (['Domain already exists', 'Not your domain', 'Atom reported a failure'] as $message) {
      try {
        $client->addDomain('b.com', 100);
        $this->fail("'$message' should throw.");
      }
      catch (ProviderException $e) {
        $this->assertFalse($e->retryable);
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests that the token never shows up in errors.
   */
  public function testTokenIsRedacted(): void {
    $client = $this->client([self::json(['message' => 'Token atomTOKEN/123 expired'], 401)]);
    try {
      $client->removeDomain('a.com');
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertStringNotContainsString('atomTOKEN', $e->getMessage());
    }
  }

}

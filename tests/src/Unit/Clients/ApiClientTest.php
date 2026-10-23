<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\ApiClient;
use Drupal\commerce_domains\ProviderException;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests retries, waits, errors and redaction shared by all API clients.
 */
#[CoversClass(ApiClient::class)]
#[Group('commerce_domains')]
class ApiClientTest extends UnitTestCase {

  /**
   * The responses still to send.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected MockHandler $mock;

  /**
   * The requests sent, with their responses.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * Returns a client that answers with the given responses.
   */
  protected function client(array $responses, float $min_interval = 0.0): RecordingApiClient {
    $this->mock = new MockHandler($responses);
    $this->history = [];
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    return new RecordingApiClient(new Client(['handler' => $stack]), 'Sec-ret-123', $min_interval);
  }

  /**
   * Tests that rate limits and server errors are retried.
   */
  public function testRetriesRateLimitsAndServerErrors(): void {
    $client = $this->client([
      new Response(429, ['Retry-After' => '3']),
      new Response(503),
      new Response(200, [], '{"ok": true}'),
    ]);
    $this->assertSame(['ok' => TRUE], $client->get('things'));
    // Retry-After when given, else 2^attempt.
    $this->assertSame([3, 4], $client->waits);
    $this->assertCount(3, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('https://api.example.test/things?token=Sec-ret-123', (string) $request->getUri());
    $this->assertSame('Sec-ret-123', $request->getHeaderLine('X-Secret'));
    $this->assertStringStartsWith('CommerceDomains/', $request->getHeaderLine('User-Agent'));
  }

  /**
   * Tests that a long Retry-After is capped.
   */
  public function testRetryWaitIsCapped(): void {
    $client = $this->client([new Response(429, ['Retry-After' => '999']), new Response(200, [], '[]')]);
    $client->get('things');
    $this->assertSame([30], $client->waits);
  }

  /**
   * Tests giving up after the last attempt, as a retryable failure.
   */
  public function testGivesUpAfterLastAttempt(): void {
    $client = $this->client([new Response(500), new Response(502), new Response(503, [], '{"message": "Down"}')]);
    try {
      $client->get('things');
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertSame('Test GET things: HTTP 503: Down', $e->getMessage());
    }
    $this->assertSame([2, 4], $client->waits);
  }

  /**
   * Tests that dropped connections are retried and secrets kept out of errors.
   */
  public function testConnectionErrorsAreRedacted(): void {
    $request = new Request('GET', 'https://api.example.test/things?token=Sec-ret-123');
    $error = new ConnectException('cURL error 7 for https://api.example.test/things?token=Sec-ret-123', $request);
    $client = $this->client([$error, $error, $error]);
    try {
      $client->get('things');
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertStringNotContainsString('Sec-ret-123', $e->getMessage());
      $this->assertStringContainsString('token=***', $e->getMessage());
    }
    $this->assertSame([2, 4], $client->waits);
  }

  /**
   * Tests which client errors are worth retrying later.
   */
  public function testClientErrors(): void {
    foreach ([400 => FALSE, 404 => FALSE, 422 => FALSE, 401 => TRUE, 403 => TRUE] as $status => $retryable) {
      $client = $this->client([new Response($status, [], '{"message": "Key Sec-ret-123 refused"}')]);
      try {
        $client->get('things');
        $this->fail("HTTP $status should fail.");
      }
      catch (ProviderException $e) {
        $this->assertSame($retryable, $e->retryable, "HTTP $status");
        $this->assertSame("Test GET things: HTTP $status: Key *** refused", $e->getMessage());
      }
      $this->assertSame([], $client->waits, "HTTP $status is not retried now.");
    }
  }

  /**
   * Tests statuses that count as success, like 404 on a delete.
   */
  public function testOkStatuses(): void {
    $client = $this->client([new Response(404, [], '{"message": "Gone"}')]);
    $this->assertSame([], $client->get('things/gone.com', [404]));
  }

  /**
   * Tests a response that isn't JSON.
   */
  public function testNonJsonBody(): void {
    $client = $this->client([new Response(204)]);
    $this->assertSame([], $client->get('things'));
  }

  /**
   * Tests collecting per-domain failures.
   */
  public function testEachDomain(): void {
    $client = $this->client([]);
    $seen = [];
    $errors = $client->eachDomain(['a.com', 'b.com', 'c.com'], function (string $name) use (&$seen): void {
      $seen[] = $name;
      if ($name === 'b.com') {
        throw new ProviderException('b.com was refused.');
      }
    });
    $this->assertSame(['a.com', 'b.com', 'c.com'], $seen);
    $this->assertSame(['b.com' => 'b.com was refused.'], $errors);
  }

  /**
   * Tests that a failure hitting every domain stops the run.
   */
  public function testEachDomainStopsOnRetryableFailure(): void {
    $client = $this->client([]);
    $seen = [];
    $this->expectException(ProviderException::class);
    try {
      $client->eachDomain(['a.com', 'b.com'], function (string $name) use (&$seen): void {
        $seen[] = $name;
        throw new ProviderException('Rate limited.', TRUE);
      });
    }
    finally {
      $this->assertSame(['a.com'], $seen);
    }
  }

  /**
   * Tests pacing for APIs that take one request at a time.
   */
  public function testThrottle(): void {
    $client = $this->client([new Response(200, [], '[]'), new Response(200, [], '[]')], 0.2);
    $start = microtime(TRUE);
    $client->get('one');
    $client->get('two');
    $this->assertGreaterThanOrEqual(0.19, microtime(TRUE) - $start);
  }

}

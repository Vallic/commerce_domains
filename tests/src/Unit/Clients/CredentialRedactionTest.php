<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\AfternicClient;
use Drupal\commerce_domains\Clients\ApiClient;
use Drupal\commerce_domains\Clients\AtomClient;
use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\Clients\UnstoppableClient;
use Drupal\commerce_domains\ProviderException;
use Drupal\Component\Uuid\Php;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that credentials stay out of client errors, however they are spelled.
 */
#[CoversClass(ApiClient::class)]
#[CoversClass(AtomClient::class)]
#[CoversClass(AfternicClient::class)]
#[CoversClass(PorkbunClient::class)]
#[CoversClass(UnstoppableClient::class)]
#[Group('commerce_domains')]
class CredentialRedactionTest extends ClientTestBase {

  // URLs and JSON spell the slash, plus, space and tilde differently.
  protected const SECRET = 'Sec/ret+12 3~';

  /**
   * Asserts that no exception in a chain quotes the secret in any spelling.
   */
  protected function assertNoSecret(\Throwable $e): void {
    $spellings = [
      self::SECRET,
      rawurlencode(self::SECRET),
      urlencode(self::SECRET),
      substr((string) json_encode(self::SECRET), 1, -1),
    ];
    for ($current = $e; $current; $current = $current->getPrevious()) {
      foreach ($spellings as $spelling) {
        $this->assertStringNotContainsString($spelling, $current->getMessage());
      }
    }
  }

  /**
   * Tests that connection errors are redacted and not chained to Guzzle's.
   */
  public function testConnectionErrors(): void {
    $url = 'https://api.example.test/things?token=' . rawurlencode(self::SECRET);
    $error = new ConnectException("cURL error 7 for $url", new Request('GET', $url, ['X-Secret' => self::SECRET]));
    $client = new RecordingApiClient($this->http([$error, $error, $error]), self::SECRET);
    try {
      $client->get('things');
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertStringContainsString('cURL error 7 for https://api.example.test/things?token=***', $e->getMessage());
      $this->assertNoSecret($e);
      // Guzzle's exception holds the request, auth headers and all.
      $this->assertNull($e->getPrevious());
    }
  }

  /**
   * Tests failures that quote a response body echoing the request.
   */
  public function testQuotedBodies(): void {
    $echo = self::json(['success' => FALSE, 'request' => ['api_token' => self::SECRET]]);
    foreach (['addDomain' => ['a.com', 100], 'listDomains' => ['standard']] as $method => $arguments) {
      $client = new AtomClient($this->http([$echo]), self::SECRET, '4242');
      try {
        $client->$method(...$arguments);
        $this->fail("$method should fail.");
      }
      catch (ProviderException $e) {
        $this->assertStringContainsString('Atom reported a failure: {"success":false,"request":{"api_token":"***"}}', $e->getMessage(), $method);
        $this->assertNoSecret($e);
      }
    }
  }

  /**
   * Tests per-domain errors, which syncs log and Drush prints.
   */
  public function testDomainErrors(): void {
    $error = ['code' => 'FORBIDDEN', 'message' => 'Secret ' . self::SECRET . ' may not'];
    $response = self::json(['a.com' => ['code' => 403, 'error' => $error]]);
    $client = new AfternicClient($this->http([$response]), new Php(), 'KEY', self::SECRET);
    $this->assertSame(['a.com' => 'FORBIDDEN: Secret *** may not'], $client->deleteDomains(['a.com']));

    $result = ['status' => 'ERROR', 'message' => 'Key ' . self::SECRET . ' refused'];
    $response = self::json(['status' => 'SUCCESS', 'results' => ['a.com' => $result]]);
    $client = new PorkbunClient($this->http([$response]), 'pk1_key', self::SECRET, 0);
    try {
      $client->updateAutoRenew('a.com', TRUE);
      $this->fail('The refusal should throw.');
    }
    catch (ProviderException $e) {
      $this->assertSame('Porkbun updateAutoRenew a.com: Key *** refused', $e->getMessage());
    }

    $response = self::json([
      'results' => [
        ['domainName' => 'a.com', 'success' => FALSE, 'error' => 'Bearer ' . self::SECRET . ' may not list'],
        ['domainName' => 'b.com', 'success' => TRUE],
      ],
    ]);
    $client = new UnstoppableClient($this->http([$response]), self::SECRET);
    $results = $client->batch('ud_listing_create', 'domains', [['domainName' => 'a.com'], ['domainName' => 'b.com']]);
    $this->assertSame('Bearer *** may not list', $results[0]['error']);
    $this->assertSame(['domainName' => 'b.com', 'success' => TRUE], $results[1]);
  }

}

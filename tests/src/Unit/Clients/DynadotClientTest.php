<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\commerce_domains\Clients\DynadotClient;
use Drupal\commerce_domains\ProviderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Dynadot api3 client.
 */
#[CoversClass(DynadotClient::class)]
#[Group('commerce_domains')]
class DynadotClientTest extends ClientTestBase {

  /**
   * Returns a client answering with the given responses.
   */
  protected function client(array $responses, int $requests_per_minute = 6000): DynadotClient {
    return new DynadotClient($this->http($responses), 'DynKey77', $requests_per_minute);
  }

  /**
   * Builds a list_domain page.
   */
  protected static function page(int $from, int $count): array {
    $domains = array_map(fn($i) => ['Name' => "p$i.com", 'isForSale' => 'no'], range($from, $from + $count - 1));
    return ['ListDomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'MainDomains' => $domains]];
  }

  /**
   * Tests paging through the account.
   */
  public function testListDomains(): void {
    $client = $this->client([self::json(self::page(0, 500)), self::json(self::page(500, 3))]);
    $this->assertCount(503, $client->listDomains());
    $this->assertSame('GET https://api.dynadot.com/api3.json', $this->call(0));
    $this->assertSame([
      'key' => 'DynKey77',
      'command' => 'list_domain',
      'count_per_page' => '500',
      'page_index' => '2',
      'sort' => 'NameAsc',
    ], $this->query(1));
  }

  /**
   * Tests stopping when the API ignores the page parameter.
   */
  public function testRepeatedPageStopsPaging(): void {
    $client = $this->client([self::json(self::page(0, 500)), self::json(self::page(0, 500))]);
    $this->assertCount(500, $client->listDomains());
    $this->assertCount(2, $this->history);
  }

  /**
   * Tests that account read failures are retryable and redacted.
   */
  public function testListFailure(): void {
    $client = $this->client([self::json(['Response' => ['ResponseCode' => '-1', 'Error' => 'invalid key DynKey77']])]);
    try {
      $client->listDomains();
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertTrue($e->retryable);
      $this->assertSame('Dynadot list_domain: invalid key ***', $e->getMessage());
    }
  }

  /**
   * Tests listing lookups and writes.
   */
  public function testListingCalls(): void {
    $client = $this->client([
      self::json([
        'GetListingsItemResponse' => [
          'ResponseCode' => 0,
          'Status' => 'success',
          'Listing' => ['Domain' => 'a.com', 'Price' => '1363'],
        ],
      ]),
      self::json([
        'GetListingsItemResponse' => ['ResponseCode' => -1, 'Status' => 'error', 'Error' => 'listing not found'],
      ]),
      // One documented example spells the key "ResponseCode: ".
      self::json(['SetForSaleResponse' => ['ResponseCode: ' => '0', 'Status' => 'Success']]),
      self::json([
        'SetForSaleResponse' => [
          'SetForSaleHeader' => ['ResponseCode' => '-1', 'Status' => 'error', 'Error' => 'Too new'],
        ],
      ]),
      self::json(['SetForSaleResponse' => ['ResponseCode' => 0, 'Status' => 'success']]),
    ]);
    $this->assertSame(['Domain' => 'a.com', 'Price' => '1363'], $client->getListingItem('a.com'));
    $this->assertSame(['command' => 'get_listing_item', 'domain' => 'a.com', 'currency' => 'usd'], array_diff_key($this->query(0), ['key' => 1]));
    $this->assertNull($client->getListingItem('b.com'));

    $client->setForSale('a.com', ['listing_type' => 'buy_now', 'price' => 10]);
    $this->assertSame([
      'command' => 'set_for_sale',
      'domains' => 'a.com',
      'for_sale_type' => 'marketplace',
      'listing_type' => 'buy_now',
      'price' => '10',
    ], array_diff_key($this->query(2), ['key' => 1]));
    try {
      $client->setForSale('b.com', ['price' => 10]);
      $this->fail('The request should fail.');
    }
    catch (ProviderException $e) {
      $this->assertFalse($e->retryable);
      $this->assertSame('Dynadot set_for_sale: Too new', $e->getMessage());
    }
    $client->cancelSale('a.com');
    $this->assertSame('not_for_sale', $this->query(4)['for_sale_type']);
  }

  /**
   * Tests pacing at the account's rate limit.
   */
  public function testRateLimit(): void {
    $client = $this->client([self::json(self::page(0, 1)), self::json(self::page(0, 1))], 300);
    $start = microtime(TRUE);
    $client->listDomains();
    $client->listDomains();
    $this->assertGreaterThanOrEqual(0.19, microtime(TRUE) - $start, '300 a minute is one every 0.2 seconds.');
  }

}

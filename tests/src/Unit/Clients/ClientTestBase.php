<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Clients;

use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Base for API client tests: a mocked Guzzle client that records requests.
 */
abstract class ClientTestBase extends UnitTestCase {

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
   * Returns a Guzzle client that answers with the given responses.
   */
  protected function http(array $responses = []): ClientInterface {
    $this->mock = new MockHandler($responses);
    $this->history = [];
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    return new Client(['handler' => $stack]);
  }

  /**
   * Builds a JSON response.
   */
  protected static function json(mixed $body, int $status = 200, array $headers = []): Response {
    return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * Returns a recorded request: "METHOD https://host/path".
   */
  protected function call(int $index): string {
    $request = $this->history[$index]['request'];
    $uri = $request->getUri();
    return $request->getMethod() . ' ' . $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath();
  }

  /**
   * Returns a recorded request's query parameters.
   */
  protected function query(int $index): array {
    parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);
    return $query;
  }

  /**
   * Returns a recorded request's JSON body.
   */
  protected function body(int $index): mixed {
    return json_decode((string) $this->history[$index]['request']->getBody(), TRUE);
  }

  /**
   * Returns a recorded request's header.
   */
  protected function header(int $index, string $name): string {
    return $this->history[$index]['request']->getHeaderLine($name);
  }

}

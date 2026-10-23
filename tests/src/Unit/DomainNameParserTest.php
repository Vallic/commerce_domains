<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\DomainNameParser;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests domain name parsing with the Public Suffix List.
 */
#[CoversClass(DomainNameParser::class)]
#[CoversClass(DomainName::class)]
#[Group('commerce_domains')]
class DomainNameParserTest extends UnitTestCase {

  /**
   * Where the parser stores the list.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueMemoryFactory
   */
  protected KeyValueMemoryFactory $keyValue;

  /**
   * The responses list downloads get.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected MockHandler $http;

  /**
   * The parser's logger.
   *
   * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface&MockObject $logger;

  /**
   * The current time.
   *
   * @var int
   */
  protected int $now = 1_800_000_000;

  /**
   * Builds a parser that reads the bundled list and fetches through a mock.
   */
  protected function parser(): DomainNameParser {
    $this->keyValue ??= new KeyValueMemoryFactory();
    $this->http ??= new MockHandler();
    $this->logger ??= $this->createMock(LoggerInterface::class);

    $modules = $this->createMock(ModuleExtensionList::class);
    $modules->method('getPath')->with('commerce_domains')->willReturn(dirname(__DIR__, 3));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn() => $this->now);

    return new DomainNameParser(
      new Client(['handler' => HandlerStack::create($this->http)]),
      $this->keyValue,
      new MemoryBackend($time),
      $modules,
      $time,
      $this->logger,
    );
  }

  /**
   * Tests normalizing names to the form providers key them by.
   */
  public function testNormalize(): void {
    $this->assertSame('example.com', DomainName::normalize('  Example.COM. '));
    $this->assertSame('xn--mnchen-3ya.de', DomainName::normalize('München.de'));
    $this->assertSame('', DomainName::normalize(' '));
  }

  /**
   * Tests splitting registrable names into label and extension.
   */
  #[DataProvider('providerParse')]
  public function testParse(string $input, ?array $expected): void {
    $parsed = $this->parser()->parse($input);
    if ($expected === NULL) {
      $this->assertNull($parsed);
      return;
    }
    $this->assertNotNull($parsed);
    $this->assertSame($expected, [$parsed->name, $parsed->label, $parsed->extension, $parsed->unicode]);
  }

  /**
   * Data provider for testParse().
   */
  public static function providerParse(): array {
    return [
      'plain' => ['Acneta.com', ['acneta.com', 'acneta', 'com', 'acneta.com']],
      'two-label suffix' => ['blala.co.uk', ['blala.co.uk', 'blala', 'co.uk', 'blala.co.uk']],
      'new gTLD' => ['screenpoint.med', ['screenpoint.med', 'screenpoint', 'med', 'screenpoint.med']],
      'IDN' => ['münchen.de', ['xn--mnchen-3ya.de', 'xn--mnchen-3ya', 'de', 'münchen.de']],
      'unknown extension' => ['brandnew.notatld', ['brandnew.notatld', 'brandnew', 'notatld', 'brandnew.notatld']],
      'subdomain' => ['www.acneta.com', NULL],
      'no extension' => ['acneta', NULL],
      'bare extension' => ['com', NULL],
      'space' => ['bad name.com', NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * Tests finding the registrable domain of any host.
   */
  public function testRegistrableDomain(): void {
    $parser = $this->parser();
    $this->assertSame('acneta.com', $parser->registrableDomain('www.acneta.com'));
    $this->assertSame('blala.co.uk', $parser->registrableDomain('shop.blala.co.uk'));
    $this->assertSame('acneta.com', $parser->registrableDomain('ACNETA.com'));
    $this->assertNull($parser->registrableDomain('co.uk'));
    $this->assertNull($parser->registrableDomain('https://acneta.com/'));
  }

  /**
   * Tests that cron only fetches a list once a week, and keeps a good one.
   */
  public function testRefresh(): void {
    $parser = $this->parser();
    $list = "// ===BEGIN ICANN DOMAINS===\ncom\nexample\n// ===END ICANN DOMAINS===\n";
    $this->http->append(new Response(200, [], $list));
    $this->assertTrue($parser->refresh());
    $this->assertSame($list, $this->keyValue->get('commerce_domains')->get('public_suffix_list'));
    // The stored list is used from now on: "example" is now an extension.
    $this->assertSame('example', $parser->parse('foo.example')->extension);

    // Within a week nothing is fetched; the mock would throw if it were.
    $this->now += 86400;
    $this->assertFalse($parser->refresh());

    // A forced refresh that gets an unusable list keeps the stored one.
    $this->logger->expects($this->once())->method('warning');
    $this->http->append(new Response(500, [], 'Down'));
    $this->assertFalse($parser->refresh(TRUE));
    $this->assertSame($list, $this->keyValue->get('commerce_domains')->get('public_suffix_list'));
  }

  /**
   * Tests that a response that isn't the list is refused rather than stored.
   */
  public function testRefreshRefusesInvalidList(): void {
    $parser = $this->parser();
    $this->logger->expects($this->once())->method('warning');
    // E.g. a captive portal answering with its own page.
    $this->http->append(new Response(200, [], '<html><body>Sign in to the network</body></html>'));
    $this->assertFalse($parser->refresh(TRUE));
    $this->assertNull($this->keyValue->get('commerce_domains')->get('public_suffix_list'));
    $this->assertSame('co.uk', $parser->parse('blala.co.uk')->extension, 'The bundled list is still used.');
  }

}

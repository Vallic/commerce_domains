<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\GnameClient;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\Gname;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the GNAME provider.
 */
#[CoversClass(Gname::class)]
#[CoversClass(GnameClient::class)]
#[Group('commerce_domains')]
class GnameTest extends ProviderTestBase {

  protected const KEYS = ['app_id' => 'gn-app', 'app_key' => 'GnKey77'];

  /**
   * Returns a recorded request's form parameters.
   */
  protected function form(int $index): array {
    parse_str((string) $this->history[$index]['request']->getBody(), $form);
    return $form;
  }

  /**
   * Returns a GNAME date some days from now.
   */
  protected static function inDays(int $days): string {
    return date('Y-m-d H:i:s', time() + $days * 86400);
  }

  /**
   * Tests the signature against GNAME's documented example.
   */
  public function testSignature(): void {
    $parameters = ['ym' => 'gname.com', 'appid' => 'APPID', 'url' => ' https://www.gname.com', 'gntime' => 1234567890];
    $this->assertSame('B48D34C9A15A3F33ED61396E5BD8F1F8', GnameClient::sign($parameters, 'APPKEY'));
  }

  /**
   * Tests signed calls, registrations in Beijing time, and errors.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(Gname::class, 'gname', self::KEYS, [
      self::json([
        'code' => 1,
        'msg' => 'ok',
        'count' => '2',
        'page' => 1,
        'data' => [
          ['id' => '1202', 'ym' => 'A.com', 'dqsj' => '2021-08-02', 'zt' => '0', 'ztstr' => 'normal'],
          ['id' => '1203', 'ym' => 'b.com', 'dqsj' => '', 'zt' => '53'],
        ],
      ]),
      self::json(['code' => -1, 'msg' => 'Sorry,Authentication TOKEN error', 'data' => '']),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1627833600, NULL, NULL, 'normal'),
      'b.com' => new Registration('b.com', NULL, NULL, NULL, '53'),
    ], $plugin->fetchRegistrations());

    $this->assertSame('POST https://api.gname.com/api/domain/list', $this->call(0));
    $form = $this->form(0);
    $this->assertSame(['gn-app', '1', '100'], [$form['appid'], $form['page'], $form['limit']]);
    $token = $form['gntoken'];
    unset($form['gntoken']);
    $this->assertSame(GnameClient::sign($form, 'GnKey77'), $token);
    $this->assertStringNotContainsString('GnKey77', (string) $this->history[0]['request']->getBody());

    try {
      $plugin->fetchRegistrations();
      $this->fail('A refused signature fails the call.');
    }
    catch (ProviderException $e) {
      $this->assertSame('GNAME domain/list: Sorry,Authentication TOKEN error', $e->getMessage());
      $this->assertTrue($e->retryable);
    }
  }

  /**
   * Tests listings, and renewing them in their last month.
   */
  public function testListings(): void {
    $plugin = $this->plugin(Gname::class, 'gname', self::KEYS, [
      self::json([
        'code' => 1,
        'msg' => 'OK',
        'count' => 2,
        'data' => [
          ['id' => '11', 'ym' => 'A.com', 'zt' => '0', 'qian' => '1000.00', 'jssj' => self::inDays(200)],
          ['id' => '12', 'ym' => 'b.com', 'zt' => '0', 'qian' => '500.50', 'jssj' => self::inDays(5)],
        ],
      ]),
    ]);
    $listing = $plugin->buildListing(self::item('a.com', 1000));
    $this->assertSame(1000, $listing['price']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $listing['ends']);
    $this->assertNull($plugin->buildListing(self::item('free.com', 0)));

    $listings = $plugin->fetchListings(['a.com', 'b.com']);
    $this->assertSame([11, 1000], [$listings['a.com']['id'], $listings['a.com']['price']]);
    $this->assertSame('0', $this->form(0)['zt']);
    $this->assertSame([], $plugin->changedFields($listing, $listings['a.com']));
    $this->assertSame(['price', 'ends'], $plugin->changedFields($listing, $listings['b.com']));
    $this->assertSame(['ends'], $plugin->changedFields($listing, ['price' => 1000, 'ends' => '长期出售']));
    $this->assertSame('$500.50', $plugin->describeValue('price', 500.5));
  }

  /**
   * Tests adding, editing and removing listings.
   */
  public function testSaveAndDelete(): void {
    $ok = self::json(['code' => 1, 'msg' => 'OK', 'data' => []]);
    $plugin = $this->plugin(Gname::class, 'gname', self::KEYS, [
      $ok,
      $ok,
      self::json(['code' => 1, 'count' => 1, 'data' => [['id' => '12', 'ym' => 'b.com', 'qian' => '1', 'jssj' => '']]]),
      $ok,
      $ok,
    ]);
    $ends = '2027-10-24 12:00:00';
    $this->assertSame([], $plugin->saveListings([
      'new.com' => ['price' => 1000, 'ends' => $ends],
      'old.com' => ['price' => 2500, 'ends' => $ends],
    ], ['old.com' => ['id' => 9, 'price' => 2000]]));
    $this->assertSame('POST https://api.gname.com/api/buynow/add', $this->call(0));
    $form = $this->form(0);
    $this->assertSame(['new.com', '1000.00', $ends], [$form['ym'], $form['qian'], $form['jssj']]);
    $this->assertSame('POST https://api.gname.com/api/buynow/edit', $this->call(1));

    $this->assertSame([], $plugin->deleteListings(['b.com', 'unlisted.com']));
    $this->assertSame('POST https://api.gname.com/api/buynow/putoff', $this->call(3));
    $this->assertSame(['12', 'Removed from sale'], [$this->form(3)['id'], $this->form(3)['sm']]);
    $this->assertSame('POST https://api.gname.com/api/buynow/delete', $this->call(4));
    $this->assertCount(5, $this->history);
  }

}

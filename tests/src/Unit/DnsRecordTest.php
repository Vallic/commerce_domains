<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit;

use Drupal\commerce_domains\DnsRecord;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DNS records: hosts, checks and comparing zones.
 */
#[CoversClass(DnsRecord::class)]
#[Group('commerce_domains')]
class DnsRecordTest extends UnitTestCase {

  /**
   * Tests hosts given either way come out relative.
   */
  public function testRelativeHost(): void {
    $this->assertSame('@', DnsRecord::relativeHost('', 'example.com'));
    $this->assertSame('@', DnsRecord::relativeHost('Example.com.', 'example.com'));
    $this->assertSame('www', DnsRecord::relativeHost('www.example.com', 'example.com'));
    $this->assertSame('_dmarc.mail', DnsRecord::relativeHost('_dmarc.mail', 'example.com'));
    $this->assertSame('@', DnsRecord::fromArray(['type' => 'a', 'host' => ' ', 'value' => '1.2.3.4'])->host);
    $this->assertSame('A', DnsRecord::fromArray(['type' => 'a', 'host' => '@', 'value' => '1.2.3.4'])->type);
  }

  /**
   * Tests what a record needs to be written.
   */
  public function testProblems(): void {
    $record = fn(string $type, string $value, string $host = '@', ?int $priority = NULL) => (new DnsRecord($type, $host, $value, $priority))->problems();
    $this->assertSame([], $record('A', '192.0.2.1'));
    $this->assertSame([], $record('AAAA', '2001:db8::1'));
    $this->assertSame([], $record('CNAME', 'target.example.net', 'www'));
    $this->assertSame([], $record('MX', 'mail.example.com', '@', 10));
    $this->assertSame([], $record('TXT', 'v=spf1 -all'));
    $this->assertSame([], $record('TXT', 'v=DMARC1; p=none', '_dmarc'));
    $this->assertSame([], $record('CAA', '0 issue "letsencrypt.org"'));
    $this->assertSame([], $record('SRV', '5 5060 sip.example.com', '_sip._tcp', 10));
    $this->assertSame([], $record('A', '192.0.2.1', '*'));
    $this->assertSame([], $record('FORWARD', 'https://example.net/'), "A registrar's own type is not checked.");

    $this->assertNotSame([], $record('A', '999.1.1.1'));
    $this->assertNotSame([], $record('AAAA', '192.0.2.1'));
    $this->assertNotSame([], $record('CNAME', 'not a host', 'www'));
    $this->assertNotSame([], $record('CNAME', 'target.example.net'), 'No CNAME on the domain itself.');
    $this->assertNotSame([], $record('MX', 'mail.example.com'), 'MX needs a priority.');
    $this->assertNotSame([], $record('A', '192.0.2.1', 'bad host!'));
    $this->assertNotSame([], $record('TXT', ''));
  }

  /**
   * Tests comparing zones.
   */
  public function testDiff(): void {
    $a = new DnsRecord('A', '@', '192.0.2.1', ttl: 300, id: '7');
    $mx = new DnsRecord('MX', '@', 'Mail.Example.com.', 10, 300);
    $txt = new DnsRecord('TXT', '@', 'old');
    $fwd = new DnsRecord('FORWARD', 'www', 'https://example.net/', extra: ['value2' => '2']);
    $current = [$a, $mx, $txt, $fwd];

    $wanted = [
      // The same address with a new TTL and no registrar ID: kept.
      new DnsRecord('A', '@', '192.0.2.1', ttl: 3600),
      // The same mail server written another way: kept.
      new DnsRecord('MX', '@', 'mail.example.com', 10),
      new DnsRecord('TXT', '@', 'new'),
      new DnsRecord('FORWARD', 'www', 'https://example.net/', extra: ['value2' => '2']),
    ];
    $diff = DnsRecord::diff($current, $wanted);
    $this->assertSame([$txt], $diff['delete']);
    $this->assertEquals([new DnsRecord('TXT', '@', 'new')], $diff['create']);
    $this->assertSame([$a, $mx, $fwd], $diff['keep']);
    $this->assertCount(1, $diff['ttl']);
    $this->assertSame(3600, $diff['ttl'][0]->ttl);
    $this->assertSame('7', $diff['ttl'][0]->id, 'The registrar ID stays, to update it.');

    // The forward's redirect kind is part of the record.
    $diff = DnsRecord::diff([$fwd], [new DnsRecord('FORWARD', 'www', 'https://example.net/', extra: ['value2' => '1'])]);
    $this->assertCount(1, $diff['delete']);
    $this->assertCount(1, $diff['create']);

    // Duplicates count one each.
    $diff = DnsRecord::diff([$txt], [$txt, $txt]);
    $this->assertCount(1, $diff['keep']);
    $this->assertCount(1, $diff['create']);

    // Round trip through stored values.
    $this->assertEquals($fwd, DnsRecord::fromArray($fwd->toArray()));
  }

}

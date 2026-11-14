<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Unit\Plugin;

use Drupal\commerce_domains\Clients\NameBrightClient;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DnsRecordsInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DomainManagementInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\NameBright;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains\Registration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the NameBright provider.
 */
#[CoversClass(NameBright::class)]
#[CoversClass(NameBrightClient::class)]
#[Group('commerce_domains')]
class NameBrightTest extends ProviderTestBase {

  protected const KEYS = ['client_id' => 'nb-app', 'client_secret' => 'Nb-secret-3', 'requests_per_minute' => 60000];

  /**
   * Tests the token, then the account list.
   */
  public function testFetchRegistrations(): void {
    $plugin = $this->registrar(NameBright::class, 'namebright', self::KEYS, [
      self::json(['access_token' => 'nb-tok', 'token_type' => 'Bearer', 'expires_in' => 3600]),
      self::json([
        'domains' => [
          [
            'domainName' => 'A.com',
            'status' => 'Active',
            'expirationDate' => '2027-09-09T09:09:09Z',
            'locked' => TRUE,
            'autoRenew' => FALSE,
          ],
          ['domainName' => 'b.com'],
        ],
        'resultsTotal' => 2,
        'currentPage' => 1,
      ]),
    ]);
    $this->assertEquals([
      'a.com' => new Registration('a.com', 1820480949, TRUE, FALSE, 'Active'),
      'b.com' => new Registration('b.com', NULL),
    ], $plugin->fetchRegistrations());
    $this->assertSame('POST https://api.namebright.com/auth/token', $this->call(0));
    parse_str((string) $this->history[0]['request']->getBody(), $form);
    $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'nb-app', 'client_secret' => 'Nb-secret-3'], $form);
    $this->assertSame('GET https://api.namebright.com/domains', $this->call(1));
    $this->assertSame(['page' => '1', 'size' => '1000'], $this->query(1));
    $this->assertSame('Bearer nb-tok', $this->header(1, 'Authorization'));
  }

  /**
   * Tests fetching a few names one by one.
   */
  public function testFetchFewNames(): void {
    $plugin = $this->registrar(NameBright::class, 'namebright', self::KEYS, [
      self::json(['access_token' => 'nb-tok']),
      self::json(['domainName' => 'a.com', 'locked' => FALSE]),
      self::json(['title' => 'Not Found', 'status' => 404], 404),
    ]);
    $expected = ['a.com' => new Registration('a.com', NULL, FALSE)];
    $this->assertEquals($expected, $plugin->fetchRegistrations(['a.com', 'gone.com']));
    $this->assertSame('GET https://api.namebright.com/domains/gone.com', $this->call(2));
  }

  /**
   * Tests changing domains in the account.
   */
  public function testManagement(): void {
    $domain = [
      'domainName' => 'a.com',
      'locked' => TRUE,
      'autoRenew' => FALSE,
      'whoIsPrivacy' => TRUE,
      'status' => 'Active',
    ];
    $plugin = $this->registrar(NameBright::class, 'namebright', self::KEYS, [
      self::json(['access_token' => 'nb-tok']),
      self::json(['domainName' => 'a.com', 'nameServers' => ['NS1.Host.com', 'ns2.host.com']]),
      self::json(['domainName' => 'a.com', 'nameServers' => ['ns1.x.com', 'ns2.x.com']]),
      self::json($domain),
      self::json(['locked' => FALSE] + $domain),
      self::json(['authCode' => 'c0de'] + $domain),
      self::json(['authCode' => NULL] + $domain),
    ]);
    $this->assertInstanceOf(DomainManagementInterface::class, $plugin);
    $this->assertSame(['ns1.host.com', 'ns2.host.com'], $plugin->getNameservers('a.com'));
    $plugin->setNameservers('a.com', ['ns1.x.com', 'ns2.x.com']);
    $this->assertSame('PUT https://api.namebright.com/domains/a.com/nameservers', $this->call(2));
    $this->assertSame(['nameServers' => ['ns1.x.com', 'ns2.x.com']], $this->body(2));

    $plugin->setTransferLock('a.com', FALSE);
    $this->assertSame('PUT https://api.namebright.com/domains/a.com', $this->call(4));
    $this->assertSame(['locked' => FALSE, 'autoRenew' => FALSE, 'whoIsPrivacy' => TRUE], $this->body(4), 'The other settings are kept.');

    $this->assertSame('c0de', $plugin->getAuthCode('a.com'));
    try {
      $plugin->getAuthCode('a.com');
      $this->fail('A missing code is an error.');
    }
    catch (ProviderException $e) {
      $this->assertSame('NameBright returned no auth code for a.com.', $e->getMessage());
    }
  }

  /**
   * Tests reading and writing host records.
   */
  public function testDnsRecords(): void {
    $ours = ['nameServers' => ['ns1.namebrightdns.com', 'ns2.namebrightdns.com']];
    $zone = [
      'domainName' => 'a.com',
      'aRecords' => [],
      'aaaaRecords' => [],
      'cnameRecords' => [
        ['recordId' => 11, 'subdomain' => '*', 'redirectDomain' => 'urlforward-https.namebright.com'],
      ],
      'mxRecords' => [
        ['recordId' => 12, 'subdomain' => '@', 'mailServer' => 'mail1.namebrightmail.com', 'priority' => 10],
      ],
      'txtRecords' => [['recordId' => 13, 'subdomain' => '@', 'textRecord' => 'old']],
      'srvRecords' => [
        [
          'recordId' => 14,
          'subdomain' => NULL,
          'service' => 'sip',
          'protocol' => 'tcp',
          'priority' => 5,
          'weight' => 1,
          'port' => 5060,
          'target' => 'sip.a.com',
        ],
      ],
    ];
    $plugin = $this->registrar(NameBright::class, 'namebright', self::KEYS, [
      self::json(['access_token' => 'nb-tok']),
      self::json($ours),
      self::json($zone),
      self::json(['nameServers' => ['ns1.elsewhere.net']]),
      // The write: nameservers and records read again, one delete, one add.
      self::json($ours),
      self::json($zone),
      self::json([]),
      self::json(['recordId' => 15], 201),
      // The refused write reads them too.
      self::json($ours),
      self::json($zone),
    ]);
    $this->assertInstanceOf(DnsRecordsInterface::class, $plugin);
    $records = $plugin->getDnsRecords('a.com');
    $this->assertEquals([
      new DnsRecord('CNAME', '*', 'urlforward-https.namebright.com', id: '11'),
      new DnsRecord('MX', '@', 'mail1.namebrightmail.com', 10, id: '12'),
      new DnsRecord('TXT', '@', 'old', id: '13'),
      new DnsRecord('SRV', '_sip._tcp', '1 5060 sip.a.com', 5, id: '14'),
    ], $records);
    $this->assertSame('GET https://api.namebright.com/domains/a.com/hostrecords/all', $this->call(2));
    $this->assertNull($plugin->getDnsRecords('b.com'), 'Nameservers elsewhere: the records there do not apply.');

    $wanted = [$records[0], $records[1], $records[3], new DnsRecord('TXT', '_brandstore', 'token')];
    $plugin->setDnsRecords('a.com', $wanted);
    $this->assertSame('DELETE https://api.namebright.com/domains/a.com/hostrecords/txt/13', $this->call(6));
    $this->assertSame('POST https://api.namebright.com/domains/a.com/hostrecords/txt', $this->call(7));
    $this->assertSame(['subdomain' => '_brandstore', 'textRecord' => 'token'], $this->body(7));
    $this->assertCount(8, $this->history, 'The records that stay, SRV among them, are not written.');

    // No TTLs: one asked for is refused before anything is written.
    $this->expectException(ProviderException::class);
    $plugin->setDnsRecords('a.com', [...$records, new DnsRecord('A', '@', '192.0.2.1', ttl: 600)]);
  }

}

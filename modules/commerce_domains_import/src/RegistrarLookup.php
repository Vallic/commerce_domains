<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\ProviderPluginManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Finds a domain's registrar in public RDAP, and the provider standing for it.
 *
 * RDAP is the registration data service that replaced WHOIS: every gTLD
 * registry runs one, and so do many ccTLD registries. IANA's bootstrap file
 * names the service for each TLD; ccTLDs that publish none there (such as
 * .io, .co and .me) can't be looked up. The file and each answer are kept
 * for a day. Nothing but the domain name is sent.
 */
class RegistrarLookup {

  // IANA's list of the RDAP service for each TLD.
  public const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';

  // How long the list and each answer are kept, in seconds.
  protected const MAX_AGE = 86400;

  protected const CACHE_PREFIX = 'commerce_domains_import:rdap:';

  /**
   * RDAP base URLs keyed by TLD, once read; empty when the list is missing.
   *
   * @var array<string, string>|null
   */
  protected ?array $servers = NULL;

  /**
   * Hosts that refused more lookups in this request, as keys.
   *
   * @var array<string, true>
   */
  protected array $refused = [];

  /**
   * What each registrar provider's name matches, keyed by provider ID.
   *
   * @var array<string, string[]>|null
   */
  protected ?array $registrars = NULL;

  public function __construct(
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ProviderPluginManager $providerPluginManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Looks a domain up at its registry's RDAP service.
   *
   * @param string $name
   *   The domain, normalized: lowercase, IDNs as A-labels.
   *
   * @return \Drupal\commerce_domains_import\RdapRecord|null
   *   The registration, or NULL when the registry has no such domain.
   *
   * @throws \Drupal\commerce_domains_import\LookupException
   *   When no RDAP service covers the TLD, or the service failed.
   */
  public function lookup(string $name): ?RdapRecord {
    $cid = self::CACHE_PREFIX . 'domain:' . $name;
    if ($cached = $this->cache->get($cid)) {
      return $cached->data instanceof RdapRecord ? $cached->data : NULL;
    }
    $tld = substr((string) strrchr('.' . $name, '.'), 1);
    $servers = $this->servers();
    if (!isset($servers[$tld])) {
      throw new LookupException(sprintf('No RDAP service for .%s domains.', $tld));
    }
    $host = (string) parse_url($servers[$tld], PHP_URL_HOST);
    if (isset($this->refused[$host])) {
      throw new LookupException(sprintf('%s refused more lookups for now.', $host));
    }

    try {
      $response = $this->httpClient->request('GET', $servers[$tld] . 'domain/' . rawurlencode($name), [
        'headers' => ['Accept' => 'application/rdap+json, application/json'],
        'connect_timeout' => 5,
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException) {
      throw new LookupException(sprintf('%s could not be reached.', $host));
    }
    $status = $response->getStatusCode();
    if ($status === 404) {
      $this->cache->set($cid, FALSE, $this->time->getRequestTime() + self::MAX_AGE);
      return NULL;
    }
    if ($status === 429) {
      // Rate limited: stop asking this host until the next request.
      $this->refused[$host] = TRUE;
      throw new LookupException(sprintf('%s refused more lookups for now.', $host));
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($status !== 200 || !is_array($data)) {
      throw new LookupException(sprintf('%s answered with an error (HTTP %d).', $host, $status));
    }
    $record = self::record($data);
    $this->cache->set($cid, $record, $this->time->getRequestTime() + self::MAX_AGE);
    return $record;
  }

  /**
   * Returns the provider a registrar stands for: by its ID, or by its name.
   *
   * A provider's label or ID, without spaces and punctuation, matches a run
   * of whole words in the name: GoDaddy matches "GoDaddy.com, LLC" and "Go
   * Daddy, LLC", while Atom doesn't match "Atomic Domains". The longest match
   * wins. Only providers a domain can be registered at count: those with a
   * registrar API, or with no API at all.
   *
   * @param string $registrar
   *   A provider ID, or a registrar's name as RDAP or a CSV file gives it.
   *
   * @return string|null
   *   The provider ID, or NULL when none matches.
   */
  public function provider(string $registrar): ?string {
    $registrars = $this->registrars();
    if (isset($registrars[trim($registrar)])) {
      return trim($registrar);
    }
    $words = self::words($registrar);
    $best = NULL;
    $length = 0;
    foreach ($registrars as $id => $keys) {
      foreach ($keys as $key) {
        if (strlen($key) > $length && self::matchesWords($words, $key)) {
          $best = (string) $id;
          $length = strlen($key);
        }
      }
    }
    return $best;
  }

  /**
   * Reads the registrar and the expiry date from an RDAP domain object.
   */
  protected static function record(array $data): RdapRecord {
    $registrar = $iana = NULL;
    foreach ($data['entities'] ?? [] as $entity) {
      if (!is_array($entity) || !in_array('registrar', (array) ($entity['roles'] ?? []), TRUE)) {
        continue;
      }
      // A jCard: ["vcard", [[name, parameters, type, value], ...]].
      foreach ((array) ($entity['vcardArray'][1] ?? []) as $property) {
        if (is_array($property) && ($property[0] ?? NULL) === 'fn' && is_string($property[3] ?? NULL) && trim($property[3]) !== '') {
          $registrar = trim($property[3]);
        }
      }
      foreach ((array) ($entity['publicIds'] ?? []) as $public_id) {
        if (is_array($public_id) && ($public_id['type'] ?? NULL) === 'IANA Registrar ID' && is_scalar($public_id['identifier'] ?? NULL)) {
          $iana = (string) $public_id['identifier'];
        }
      }
      break;
    }

    $expires = NULL;
    foreach ($data['events'] ?? [] as $event) {
      if (is_array($event) && ($event['eventAction'] ?? NULL) === 'expiration' && is_string($event['eventDate'] ?? NULL)) {
        try {
          $expires = (new \DateTimeImmutable($event['eventDate']))->getTimestamp();
        }
        catch (\Exception) {
          // Left unknown.
        }
      }
    }
    return new RdapRecord($registrar, $iana, $expires);
  }

  /**
   * Returns the RDAP base URL of each TLD, from IANA's bootstrap file.
   *
   * @return array<string, string>
   *   Base URLs ending in a slash, keyed by TLD.
   *
   * @throws \Drupal\commerce_domains_import\LookupException
   *   When the file can't be read.
   */
  protected function servers(): array {
    if ($this->servers === NULL) {
      $cached = $this->cache->get(self::CACHE_PREFIX . 'bootstrap');
      $this->servers = $cached && is_array($cached->data) ? $cached->data : $this->fetchServers();
    }
    if (!$this->servers) {
      throw new LookupException('The list of RDAP services could not be read.');
    }
    return $this->servers;
  }

  /**
   * Reads IANA's bootstrap file, and keeps it for a day.
   *
   * @return array<string, string>
   *   Base URLs keyed by TLD; empty when the file couldn't be read.
   */
  protected function fetchServers(): array {
    try {
      $response = $this->httpClient->request('GET', self::BOOTSTRAP, ['connect_timeout' => 5, 'timeout' => 30]);
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (GuzzleException | \JsonException $e) {
      $this->logger->warning('Could not read the RDAP bootstrap file: @error', ['@error' => $e->getMessage()]);
      return [];
    }

    $servers = [];
    // Each service is [[TLDs], [base URLs]].
    foreach ((array) ($data['services'] ?? []) as $service) {
      if (!is_array($service) || !is_array($service[0] ?? NULL) || !is_array($service[1] ?? NULL)) {
        continue;
      }
      $urls = array_values(array_filter($service[1], 'is_string'));
      // Prefer HTTPS where a service lists both.
      usort($urls, fn(string $a, string $b) => str_starts_with($b, 'https://') <=> str_starts_with($a, 'https://'));
      if (!$urls) {
        continue;
      }
      foreach ($service[0] as $tld) {
        if (is_string($tld)) {
          $servers[mb_strtolower($tld)] = rtrim($urls[0], '/') . '/';
        }
      }
    }
    if ($servers) {
      $this->cache->set(self::CACHE_PREFIX . 'bootstrap', $servers, $this->time->getRequestTime() + self::MAX_AGE);
    }
    else {
      $this->logger->warning('The RDAP bootstrap file lists no services.');
    }
    return $servers;
  }

  /**
   * Returns what the name of each registrar provider matches.
   *
   * @return array<string, string[]>
   *   The squashed ID and label of each provider, keyed by provider ID.
   */
  protected function registrars(): array {
    if ($this->registrars === NULL) {
      $plugins = $this->providerPluginManager->getPluginIdsWith(RegistrarInterface::class);
      $this->registrars = [];
      /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
      foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
        $plugin = $provider->getPluginId();
        if ($plugin === NULL || in_array($plugin, $plugins, TRUE)) {
          $keys = [self::squash((string) $id), self::squash((string) $provider->label())];
          $this->registrars[(string) $id] = array_values(array_unique(array_filter($keys)));
        }
      }
    }
    return $this->registrars;
  }

  /**
   * Splits a name into lowercase words of letters and digits.
   *
   * @return string[]
   *   The words.
   */
  protected static function words(string $name): array {
    return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }

  /**
   * Returns a name in lowercase, without spaces and punctuation.
   */
  protected static function squash(string $name): string {
    return implode('', self::words($name));
  }

  /**
   * Whether a run of consecutive words spells the key.
   *
   * @param string[] $words
   *   The words of a registrar's name.
   * @param string $key
   *   A squashed provider name.
   */
  protected static function matchesWords(array $words, string $key): bool {
    $count = count($words);
    for ($start = 0; $start < $count; $start++) {
      $run = '';
      for ($end = $start; $end < $count && strlen($run) < strlen($key); $end++) {
        $run .= $words[$end];
      }
      if ($run === $key) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

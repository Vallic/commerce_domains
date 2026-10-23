<?php

declare(strict_types=1);

namespace Drupal\commerce_domains;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use GuzzleHttp\ClientInterface;
use Pdp\Rules;
use Pdp\SyntaxError;
use Pdp\UnableToResolveDomain;
use Psr\Log\LoggerInterface;

/**
 * Splits domain names into label and extension with the Public Suffix List.
 *
 * The list comes from a copy that cron refreshes weekly, falling back to the
 * snapshot shipped in data/. It is never downloaded during a request.
 */
class DomainNameParser {

  public const SOURCE = 'https://publicsuffix.org/list/public_suffix_list.dat';

  // How often cron fetches a fresh list.
  protected const REFRESH_INTERVAL = 7 * 86400;

  protected const CACHE_ID = 'commerce_domains:public_suffix_rules';

  // Every real list has this marker; error pages don't.
  protected const ICANN_SECTION = '===BEGIN ICANN DOMAINS===';

  /**
   * The parsed rules, once loaded.
   *
   * @var \Pdp\Rules|null
   */
  protected ?Rules $rules = NULL;

  public function __construct(
    protected ClientInterface $httpClient,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected CacheBackendInterface $cache,
    protected ModuleExtensionList $moduleList,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Parses a registrable domain name such as example.com or example.co.uk.
   *
   * Names whose extension the list doesn't know yet (a brand new TLD) are
   * split at the first dot rather than rejected.
   *
   * @return \Drupal\commerce_domains\DomainName|null
   *   The parsed name, or NULL when it isn't a registrable domain: no
   *   extension, a bare extension, a subdomain or invalid characters.
   */
  public function parse(string $name): ?DomainName {
    $name = DomainName::normalize($name);
    if (!str_contains($name, '.') || preg_match('/\s/', $name)) {
      return NULL;
    }
    try {
      $resolved = $this->rules()->getICANNDomain($name);
      // A name longer than its registrable part is a subdomain.
      if ($resolved->registrableDomain()->toString() !== $name) {
        return NULL;
      }
      $label = (string) $resolved->secondLevelDomain()->toString();
      $extension = (string) $resolved->suffix()->toString();
    }
    catch (UnableToResolveDomain) {
      [$label, $extension] = explode('.', $name, 2);
    }
    catch (SyntaxError) {
      return NULL;
    }
    if ($label === '' || $extension === '') {
      return NULL;
    }
    $unicode = function_exists('idn_to_utf8') ? (idn_to_utf8($name, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46) ?: $name) : $name;
    return new DomainName($name, $unicode, $label, $extension);
  }

  /**
   * Returns the registrable domain of any host, e.g. for www.shop.example.com.
   *
   * @return string|null
   *   The registrable domain, or NULL when the host has none.
   */
  public function registrableDomain(string $host): ?string {
    $host = DomainName::normalize($host);
    try {
      $domain = $this->rules()->getICANNDomain($host)->registrableDomain()->toString();
    }
    catch (UnableToResolveDomain | SyntaxError) {
      return NULL;
    }
    return $domain !== '' ? $domain : NULL;
  }

  /**
   * Fetches a fresh Public Suffix List when the stored one is a week old.
   *
   * @param bool $force
   *   Fetch even when the stored copy is recent.
   *
   * @return bool
   *   Whether a new list was stored.
   */
  public function refresh(bool $force = FALSE): bool {
    $store = $this->keyValueFactory->get('commerce_domains');
    if (!$force && $this->time->getRequestTime() - (int) $store->get('public_suffix_list_fetched', 0) < self::REFRESH_INTERVAL) {
      return FALSE;
    }
    try {
      $response = $this->httpClient->request('GET', self::SOURCE, ['timeout' => 30]);
      $list = (string) $response->getBody();
      // Refuse anything that isn't the list, e.g. a proxy's error page, rather
      // than break every lookup. The parser accepts almost any text.
      if (!str_contains($list, self::ICANN_SECTION)) {
        throw new \UnexpectedValueException('The response is not a Public Suffix List.');
      }
      Rules::fromString($list);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not refresh the Public Suffix List: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
    $store->setMultiple([
      'public_suffix_list' => $list,
      'public_suffix_list_fetched' => $this->time->getRequestTime(),
    ]);
    $this->cache->delete(self::CACHE_ID);
    $this->rules = NULL;
    return TRUE;
  }

  /**
   * Returns the parsed rules, from the cache when possible.
   */
  protected function rules(): Rules {
    if ($this->rules) {
      return $this->rules;
    }
    if (($cached = $this->cache->get(self::CACHE_ID)) && $cached->data instanceof Rules) {
      return $this->rules = $cached->data;
    }
    $list = $this->keyValueFactory->get('commerce_domains')->get('public_suffix_list')
      ?? file_get_contents($this->moduleList->getPath('commerce_domains') . '/data/public_suffix_list.dat');
    $this->rules = Rules::fromString($list);
    $this->cache->set(self::CACHE_ID, $this->rules);
    return $this->rules;
  }

}

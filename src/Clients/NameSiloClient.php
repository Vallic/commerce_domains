<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the NameSilo API.
 *
 * Every operation is a GET with the key in the query string, and reports its
 * outcome as a reply code rather than the HTTP status.
 *
 * @see https://www.namesilo.com/api-reference
 */
final class NameSiloClient extends ApiClient {

  protected const NETWORK = 'NameSilo';

  protected const BASE_URI = 'https://www.namesilo.com/api/';

  // Reply codes for success, and success with a caveat.
  protected const SUCCESS = ['300', '301', '302'];

  // Reply codes that can clear up without code changes: the key, the user or
  // the IP address is refused, an internal error, or a request still running.
  protected const RETRYABLE = ['110', '111', '112', '113', '201', '400'];

  // The reply code for a domain that isn't active in the account.
  protected const NOT_IN_ACCOUNT = '200';

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiKey,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches every active domain in the account, with its expiry.
   *
   * @return array[]
   *   Domain records with domain, created and expires.
   */
  public function listDomains(): array {
    $reply = $this->operation('listDomains');
    $domains = $reply['domains'] ?? [];
    // Older replies wrap the names: {"domain": ["a.com", "b.com"]}.
    if (is_array($domains) && isset($domains['domain'])) {
      $domains = (array) $domains['domain'];
    }
    return array_map(fn($domain) => is_array($domain) ? $domain : ['domain' => (string) $domain], self::listOf($domains));
  }

  /**
   * Fetches one domain's details.
   *
   * @return array|null
   *   The reply with expires, locked, auto_renew and status, or NULL when
   *   the domain isn't active in the account.
   */
  public function getDomainInfo(string $name): ?array {
    $reply = $this->operation('getDomainInfo', ['domain' => $name], [self::NOT_IN_ACCOUNT]);
    return $reply ? ['domain' => $name] + $reply : NULL;
  }

  /**
   * Replaces a domain's nameservers; NameSilo takes up to 13.
   *
   * @param string $name
   *   The domain.
   * @param string[] $nameservers
   *   The host names.
   */
  public function changeNameServers(string $name, array $nameservers): void {
    $parameters = ['domain' => $name];
    foreach (array_values(array_slice($nameservers, 0, 13)) as $i => $host) {
      $parameters['ns' . ($i + 1)] = $host;
    }
    $this->operation('changeNameServers', $parameters);
  }

  /**
   * Locks a domain against transfers, or unlocks it.
   */
  public function setLock(string $name, bool $locked): void {
    $this->operation($locked ? 'domainLock' : 'domainUnlock', ['domain' => $name]);
  }

  /**
   * Turns automatic renewal on or off.
   */
  public function setAutoRenewal(string $name, bool $enabled): void {
    $this->operation($enabled ? 'addAutoRenewal' : 'removeAutoRenewal', ['domain' => $name]);
  }

  /**
   * Has NameSilo email the auth code to the domain's administrative contact.
   */
  public function retrieveAuthCode(string $name): void {
    $this->operation('retrieveAuthCode', ['domain' => $name]);
  }

  /**
   * Fetches the account's active marketplace sales.
   *
   * @return array[]
   *   Sales with domain, status, reserve, buy_now and sale_type.
   */
  public function activeSales(): array {
    return self::listOf($this->operation('marketplaceActiveSalesOverview')['sale_details'] ?? []);
  }

  /**
   * Adds a sale, or changes one.
   *
   * @param string $name
   *   The domain.
   * @param bool $add
   *   TRUE to add the sale, FALSE to change the existing one.
   * @param array $parameters
   *   The sale settings, e.g. sale_type, buy_now and reserve.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function saveSale(string $name, bool $add, array $parameters): void {
    $this->operation('marketplaceAddOrModifySale', ['domain' => $name, 'action' => $add ? 'add' : 'modify'] + $parameters);
  }

  /**
   * Cancels a sale.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function cancelSale(string $name): void {
    $this->saveSale($name, FALSE, ['cancel_sale' => 1]);
  }

  /**
   * Runs an operation.
   *
   * @param string $operation
   *   The operation name.
   * @param array $parameters
   *   Its parameters.
   * @param string[] $empty
   *   Reply codes that mean "nothing here"; the result is then empty.
   *
   * @return array
   *   The reply.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function operation(string $operation, array $parameters = [], array $empty = []): array {
    $reply = $this->request('GET', $operation, [RequestOptions::QUERY => $parameters])['reply'] ?? [];
    // Codes come as numbers or strings.
    $code = (string) ($reply['code'] ?? '');
    if (in_array($code, self::SUCCESS, TRUE)) {
      return $reply;
    }
    if (in_array($code, $empty, TRUE)) {
      return [];
    }
    $detail = is_string($reply['detail'] ?? NULL) ? $reply['detail'] : 'no reply';
    throw new ProviderException($this->redact(sprintf('%s %s: %s (code %s)', static::NETWORK, $operation, $detail, $code)), in_array($code, self::RETRYABLE, TRUE));
  }

  /**
   * Returns a reply value as a list: a single item comes as a lone object.
   */
  protected static function listOf(mixed $value): array {
    if (!is_array($value) || !$value) {
      return [];
    }
    return array_is_list($value) ? $value : [$value];
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::QUERY] = ['version' => 1, 'type' => 'json', 'key' => $this->apiKey] + ($options[RequestOptions::QUERY] ?? []);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->apiKey];
  }

}

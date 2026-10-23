<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the GNAME API.
 *
 * Every call is a form POST signed with the app key, and reports its outcome
 * as a code in the body rather than the HTTP status. Access needs a reseller
 * account.
 *
 * @see https://www.gname.com/domain/api
 */
final class GnameClient extends ApiClient {

  protected const NETWORK = 'GNAME';

  protected const BASE_URI = 'https://api.gname.com/api/';

  protected const PAGE_SIZE = 100;

  // Stops a runaway loop if the pagination ever goes wrong.
  protected const MAX_PAGES = 1000;

  // Buy It Now states.
  public const ON_SALE = 0;

  public const OFF_SALE = 2;

  public function __construct(
    ClientInterface $httpClient,
    protected string $appId,
    protected string $appKey,
  ) {
    parent::__construct($httpClient);
  }

  /**
   * Fetches every domain in the account.
   *
   * @return array[]
   *   Domain records with ym (the domain), dqsj (expiry) and zt (status).
   */
  public function listDomains(): array {
    return $this->pages('domain/list');
  }

  /**
   * Fetches the account's Buy It Now listings.
   *
   * @param int $status
   *   ON_SALE or OFF_SALE.
   *
   * @return array[]
   *   Listings with id, ym (the domain), qian (the USD price) and jssj (when
   *   the sale ends).
   */
  public function listSales(int $status = self::ON_SALE): array {
    return $this->pages('buynow/list/sales', ['zt' => $status]);
  }

  /**
   * Lists a domain, or changes its listing.
   *
   * @param string $name
   *   The domain.
   * @param bool $add
   *   TRUE to add the listing, FALSE to change the existing one.
   * @param float $price
   *   The USD price.
   * @param string $ends
   *   When the sale ends, as Y-m-d H:i:s.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function saveSale(string $name, bool $add, float $price, string $ends): void {
    $this->call($add ? 'buynow/add' : 'buynow/edit', [
      'ym' => $name,
      'qian' => number_format($price, 2, '.', ''),
      'jssj' => $ends,
    ]);
  }

  /**
   * Takes a listing off sale and deletes it, so the domain can be relisted.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  public function removeSale(int $id): void {
    $this->call('buynow/putoff', ['id' => $id, 'sm' => 'Removed from sale']);
    $this->call('buynow/delete', ['id' => $id]);
  }

  /**
   * Fetches every page of a list.
   *
   * @return array[]
   *   The items.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function pages(string $path, array $parameters = []): array {
    $items = [];
    for ($page = 1; $page <= self::MAX_PAGES; $page++) {
      $response = $this->call($path, ['page' => $page, 'limit' => self::PAGE_SIZE] + $parameters);
      $batch = is_array($response['data'] ?? NULL) ? array_values($response['data']) : [];
      array_push($items, ...$batch);
      // Counts come as strings.
      if (!$batch || count($items) >= (int) ($response['count'] ?? 0)) {
        break;
      }
    }
    return $items;
  }

  /**
   * Sends a signed call.
   *
   * @return array
   *   The response, with code, msg and data.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function call(string $path, array $parameters = []): array {
    $response = $this->request('POST', $path, [RequestOptions::FORM_PARAMS => $parameters]);
    if ((int) ($response['code'] ?? 0) !== 1) {
      $message = is_string($response['msg'] ?? NULL) ? $response['msg'] : 'no reply';
      // A refused signature or app ID is fixed outside the code.
      $retryable = (bool) preg_match('/token|appid|authentication|\bip\b/i', $message);
      throw new ProviderException($this->redact(sprintf('%s %s: %s', static::NETWORK, $path, $message)), $retryable);
    }
    return $response;
  }

  /**
   * Signs the parameters of a call.
   *
   * MD5 of the parameters sorted by name and URL-encoded, with the app key
   * appended, in upper case.
   */
  public static function sign(array $parameters, string $key): string {
    $parameters = array_map(fn($value) => trim((string) $value), $parameters);
    ksort($parameters, SORT_STRING);
    return strtoupper(md5(http_build_query($parameters, '', '&', PHP_QUERY_RFC1738) . $key));
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $parameters = ['appid' => $this->appId, 'gntime' => time()] + ($options[RequestOptions::FORM_PARAMS] ?? []);
    $parameters['gntoken'] = self::sign($parameters, $this->appKey);
    $options[RequestOptions::FORM_PARAMS] = $parameters;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->appKey];
  }

}

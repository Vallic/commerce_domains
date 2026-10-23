<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Talks to the Namecheap API, which answers in XML.
 *
 * Calls must come from an IPv4 address allowed in the account, sent along as
 * ClientIp.
 *
 * @see https://www.namecheap.com/support/api/intro/
 */
final class NamecheapClient extends ApiClient {

  protected const NETWORK = 'Namecheap';

  protected const BASE_URI = 'https://api.namecheap.com/';

  // The API's largest page.
  protected const PAGE_SIZE = 100;

  public function __construct(
    ClientInterface $httpClient,
    protected string $apiUser,
    protected string $apiKey,
    protected string $clientIp,
    int $requestsPerMinute = 50,
  ) {
    parent::__construct($httpClient, $requestsPerMinute);
  }

  /**
   * Fetches every domain in the account.
   *
   * @return array[]
   *   The attributes of each Domain element, e.g. Name and Expires.
   */
  public function listDomains(): array {
    $domains = [];
    for ($page = 1;; $page++) {
      $response = $this->command('namecheap.domains.getList', ['Page' => $page, 'PageSize' => self::PAGE_SIZE]);
      $batch = [];
      foreach ($response->DomainGetListResult->Domain ?? [] as $domain) {
        $batch[] = array_map('strval', iterator_to_array($domain->attributes()));
      }
      array_push($domains, ...$batch);
      if (count($batch) < self::PAGE_SIZE || count($domains) >= (int) $response->Paging->TotalItems) {
        return $domains;
      }
    }
  }

  /**
   * Runs an API command.
   *
   * @return \SimpleXMLElement
   *   The CommandResponse element.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function command(string $command, array $parameters = []): \SimpleXMLElement {
    $response = $this->request('GET', 'xml.response', [
      RequestOptions::QUERY => ['Command' => $command] + $parameters,
    ]);
    $xml = $response['xml'] ?? NULL;
    if (!$xml instanceof \SimpleXMLElement) {
      throw new ProviderException(sprintf('%s %s returned no XML.', static::NETWORK, $command), TRUE);
    }
    if ((string) $xml['Status'] !== 'OK') {
      $error = $xml->Errors->Error[0] ?? NULL;
      $number = $error ? (string) $error['Number'] : '';
      // Numbers from 1010000 are about the key, user or IP address, which are
      // fixed outside the code.
      throw new ProviderException($this->redact(sprintf('%s %s: %s', static::NETWORK, $command, $this->describeError(200, $response))), str_starts_with($number, '101'));
    }
    return $xml->CommandResponse;
  }

  /**
   * {@inheritdoc}
   */
  protected function decode(ResponseInterface $response): array {
    $xml = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
    return $xml === FALSE ? [] : ['xml' => $xml];
  }

  /**
   * {@inheritdoc}
   */
  protected function describeError(int $status, array $body): string {
    $error = isset($body['xml']) ? ($body['xml']->Errors->Error[0] ?? NULL) : NULL;
    return $error ? sprintf('%s (error %s)', trim((string) $error), $error['Number']) : "HTTP $status";
  }

  /**
   * {@inheritdoc}
   */
  protected function attemptHeaders(): array {
    return ['Accept' => 'application/xml'] + parent::attemptHeaders();
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::QUERY] = [
      'ApiUser' => $this->apiUser,
      'ApiKey' => $this->apiKey,
      'UserName' => $this->apiUser,
      'ClientIp' => $this->clientIp,
    ] + ($options[RequestOptions::QUERY] ?? []);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function secrets(): array {
    return [$this->apiKey];
  }

}

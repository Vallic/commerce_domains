<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Clients;

use Drupal\commerce_domains\ProviderException;
use GuzzleHttp\RequestOptions;

/**
 * OAuth2 client-credentials tokens for an ApiClient.
 *
 * The using class defines TOKEN_PATH and has $clientId and $clientSecret
 * properties. The credentials go in the form body, which every provider
 * accepts; some refuse HTTP Basic.
 */
trait ClientCredentialsTrait {

  /**
   * The access token, once fetched.
   *
   * @var string|null
   */
  protected ?string $token = NULL;

  /**
   * When the access token expires, as a Unix time.
   *
   * @var int
   */
  protected int $tokenExpires = 0;

  /**
   * Returns extra token request parameters, e.g. the scopes.
   */
  protected function tokenParameters(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function authorize(array $options): array {
    $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $this->token();
    return $options;
  }

  /**
   * Returns an access token, fetching a new one when it is about to expire.
   *
   * @throws \Drupal\commerce_domains\ProviderException
   */
  protected function token(): string {
    if ($this->token !== NULL && time() < $this->tokenExpires - 60) {
      return $this->token;
    }
    try {
      $response = $this->send('POST', static::TOKEN_PATH, [
        RequestOptions::FORM_PARAMS => [
          'grant_type' => 'client_credentials',
          'client_id' => $this->clientId,
          'client_secret' => $this->clientSecret,
        ] + $this->tokenParameters(),
      ]);
    }
    catch (ProviderException $e) {
      // Bad or revoked credentials come back as 400, 401 or 403; either way
      // the fix is outside the code, so keep queued work for later.
      throw new ProviderException($e->getMessage(), TRUE, $e);
    }
    if (empty($response['access_token']) || !is_string($response['access_token'])) {
      throw new ProviderException(sprintf('%s returned no access token.', static::NETWORK), TRUE);
    }
    $this->token = $response['access_token'];
    $this->tokenExpires = time() + (int) ($response['expires_in'] ?? 3600);
    return $this->token;
  }

}

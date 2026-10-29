<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Controller;

use Drupal\commerce_domains\DomainNameParser;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sends visitors of a parked domain to its page here.
 *
 * Registrars forward a parked domain to one URL; the Referer, or a
 * "domain" query parameter where the forward can add one, says which domain
 * the visitor typed. Domains not sold here go to the fallback path, which
 * the route can set.
 */
final class LanderController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected DomainNameParser $parser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Redirects to the visitor's domain, or to the fallback.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $fallback
   *   A path on this site, for domains not sold here.
   */
  public function redirect(Request $request, string $fallback = '/'): TrustedRedirectResponse {
    $url = $this->productUrl($request) ?? Url::fromUserInput($fallback)->toString();
    // Each visitor may come from another domain.
    return new TrustedRedirectResponse($url, 302, ['Cache-Control' => 'max-age=0, s-maxage=0']);
  }

  /**
   * Returns the page of the domain the visitor came for, if sold here.
   */
  protected function productUrl(Request $request): ?string {
    $referer = (string) $request->headers->get('referer', '');
    // The Referer is a URL; only its host names the domain.
    $host = (string) $request->query->get('domain', '') ?: (parse_url($referer, PHP_URL_HOST) ?: $referer);
    $domain = $host ? $this->parser->registrableDomain($host) : NULL;
    if (!$domain) {
      return NULL;
    }
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties([
      'domain' => $domain,
      'status' => TRUE,
    ]);
    $product = reset($products);
    return $product ? $product->toUrl()->toString() : NULL;
  }

}

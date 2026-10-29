<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_import\Kernel;

use Drupal\commerce_domains_import\DomainImporter;
use Drupal\commerce_domains_import\ImportOptions;
use Drupal\commerce_domains_import\ImportReport;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Base for import tests: domains with sale terms, and helpers to import.
 */
abstract class ImportKernelTestBase extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_import'];

  /**
   * What the mocked HTTP client answers, keyed by URL; others get a 404.
   *
   * An array is sent as JSON.
   *
   * @var array<string, \Psr\Http\Message\ResponseInterface|array>
   */
  protected array $responses = [];

  /**
   * The URLs requested from the mocked HTTP client.
   *
   * @var string[]
   */
  protected array $requests = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('commerce_domains_sale_terms'), 'commerce_product_variation', 'default');

    // Nothing may reach the network: RDAP answers come from $responses.
    $handler = function (RequestInterface $request, array $options) {
      $url = (string) $request->getUri();
      $this->requests[] = $url;
      $response = $this->responses[$url] ?? new Response(404);
      if (is_array($response)) {
        $response = new Response(200, ['Content-Type' => 'application/rdap+json'], (string) json_encode($response));
      }
      return (new MockHandler([$response]))($request, $options);
    };
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($handler)]));
  }

  /**
   * Returns the import's choices: the default product type and store, USD.
   */
  protected function options(array $values = []): ImportOptions {
    return new ImportOptions(...$values + [
      'productType' => 'default',
      'store' => (int) $this->store->id(),
      'currency' => 'USD',
    ]);
  }

  /**
   * Imports rows and returns the report.
   *
   * @param \Drupal\commerce_domains_import\ImportRow[] $rows
   *   The rows.
   * @param array $options
   *   Named ImportOptions arguments.
   */
  protected function import(array $rows, array $options = []): ImportReport {
    $options = $this->options($options);
    $report = new ImportReport($options->dryRun);
    $this->container->get(DomainImporter::class)->import($rows, $options, $report);
    return $report;
  }

  /**
   * Loads a domain's product afresh, or NULL when the catalog lacks it.
   */
  protected function product(string $domain): ?ProductInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $storage->resetCache();
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    $products = $storage->loadByProperties(['domain' => $domain]);
    $product = reset($products);
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * Returns a domain's variation.
   */
  protected function variation(string $domain): ProductVariationInterface {
    $variations = $this->product($domain)->getVariations();
    return reset($variations);
  }

  /**
   * Returns the URLs requested from the mocked HTTP client so far.
   *
   * @return string[]
   *   The URLs.
   */
  protected function requested(): array {
    return $this->requests;
  }

  /**
   * Returns a report's errors as "line: domain: message" strings.
   */
  protected static function errors(ImportReport $report): array {
    return array_map(fn(array $error) => $error['line'] . ': ' . $error['domain'] . ': ' . $error['message'], $report->errors);
  }

}

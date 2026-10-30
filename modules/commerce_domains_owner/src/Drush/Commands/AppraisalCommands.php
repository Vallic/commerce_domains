<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Drush\Commands;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\Appraiser;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

/**
 * Drush commands for domain appraisals.
 */
final class AppraisalCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Appraiser $appraiser,
  ) {
    parent::__construct();
  }

  /**
   * Appraises domains now, and records value, score and extensions taken.
   *
   * Counts toward the day's allowance in the portfolio settings, but isn't
   * held to it: the appraisal service's own limit still applies.
   */
  #[CLI\Command(name: 'commerce-domains:appraise', aliases: ['cdap'])]
  #[CLI\Option(name: 'domains', description: 'Comma-separated domains to appraise instead of the next ones due.')]
  #[CLI\Option(name: 'limit', description: 'How many of the domains due to appraise.')]
  #[CLI\Usage(name: 'drush cdap --limit=20', description: 'Appraise the next 20 domains due: never appraised first.')]
  #[CLI\Usage(name: 'drush cdap --domains=example.com', description: 'Appraise one domain again.')]
  public function appraise(array $options = ['domains' => self::REQ, 'limit' => 10]): int {
    $provider = $this->appraiser->provider();
    if (!$provider) {
      $this->logger()->error(dt('No enabled provider is set up to appraise domains, e.g. Atom.'));
      return self::EXIT_FAILURE;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    if ($options['domains']) {
      $names = array_map(DomainName::normalize(...), StringUtils::csvToArray($options['domains']));
      /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
      $products = $storage->loadByProperties(['domain' => $names]);
      $found = array_map(fn($product) => (string) $product->get('domain')->value, $products);
      foreach (array_diff($names, $found) as $name) {
        $this->logger()->warning(dt('@domain is not in the catalog.', ['@domain' => $name]));
      }
    }
    else {
      $products = $storage->loadMultiple($this->appraiser->due(max(1, (int) $options['limit'])));
    }
    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */

    $rows = [];
    $status = self::EXIT_SUCCESS;
    foreach ($products as $product) {
      try {
        $appraisal = $this->appraiser->appraise($product, $provider);
      }
      catch (ProviderException $e) {
        $this->logger()->error($e->getMessage());
        $status = self::EXIT_FAILURE;
        break;
      }
      $rows[] = [
        $appraisal->name,
        $appraisal->value === NULL ? '-' : '$' . number_format($appraisal->value),
        $appraisal->score ?? '-',
        $appraisal->tldCount ?? '-',
      ];
    }
    $this->appraiser->countToday(count($rows));
    if ($rows) {
      $this->io()->table([dt('Domain'), dt('Appraisal'), dt('Score'), dt('Extensions')], $rows);
    }
    elseif ($status === self::EXIT_SUCCESS) {
      $this->io()->text(dt('No domains are due for an appraisal.'));
    }
    return $status;
  }

}

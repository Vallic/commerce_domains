<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Drush\Commands;

use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\RenewalCostDefaults;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush command for the default renewal costs.
 */
final class RenewalCostCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected RenewalCostDefaults $defaults,
  ) {
    parent::__construct();
  }

  /**
   * Fills in the default renewal costs from Porkbun's public prices.
   *
   * For each extension of the catalog's domains without a cost yet, what a
   * year's renewal costs at Porkbun, in US dollars.
   */
  #[CLI\Command(name: 'commerce-domains:renewal-costs', aliases: ['cdrenew'])]
  #[CLI\Option(name: 'replace', description: 'Replace the costs already set for those extensions.')]
  #[CLI\Option(name: 'dry-run', description: 'Show the costs, and save nothing.')]
  #[CLI\Usage(name: 'drush cdrenew --dry-run', description: "Show Porkbun's renewal prices for the catalog's extensions.")]
  public function fill(array $options = ['replace' => FALSE, 'dry-run' => FALSE]): int {
    try {
      $fill = $this->defaults->fromPorkbun((bool) $options['replace']);
    }
    catch (ProviderException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $rows = [];
    foreach ($fill['added'] as $tld => $price) {
      $rows[] = [$tld, RenewalCostDefaults::CURRENCY . ' ' . $price, dt('added')];
    }
    foreach ($fill['replaced'] as $tld => $price) {
      $rows[] = [$tld, RenewalCostDefaults::CURRENCY . ' ' . $price, dt('replaced')];
    }
    foreach ($fill['unpriced'] as $tld) {
      $rows[] = [$tld, '-', dt('not sold by Porkbun')];
    }
    if ($rows) {
      $this->io()->table([dt('Extension'), dt('Renewal'), dt('')], $rows);
    }
    if (!$fill['added'] && !$fill['replaced']) {
      $this->io()->text(dt('Nothing to fill in.'));
    }
    elseif (!$options['dry-run']) {
      $this->defaults->save($fill['costs']);
      $this->io()->text(dt('Saved @count default renewal costs.', ['@count' => count($fill['costs'])]));
    }
    return self::EXIT_SUCCESS;
  }

}

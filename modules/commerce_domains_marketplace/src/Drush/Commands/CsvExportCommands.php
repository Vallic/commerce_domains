<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Drush\Commands;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains_marketplace\CsvExport;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the CSV files marketplaces import.
 */
final class CsvExportCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CsvExport $export,
  ) {
    parent::__construct();
  }

  /**
   * Prints a provider's CSV file of the domains for sale.
   */
  #[CLI\Command(name: 'commerce-domains:csv-export', aliases: ['cdcsv'])]
  #[CLI\Argument(name: 'provider', description: 'The provider ID, e.g. sedo.')]
  #[CLI\Option(name: 'file', description: 'The file, for providers that take several, e.g. external for Dynadot. Defaults to the first.')]
  #[CLI\Usage(name: 'drush cdcsv sedo > sedo.csv', description: "Write Sedo's upload file.")]
  #[CLI\Usage(name: 'drush cdcsv dynadot --file=external', description: 'Print the Dynadot file for domains registered elsewhere.')]
  public function export(string $provider, array $options = ['file' => self::REQ]): int {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $entity */
    $entity = $this->entityTypeManager->getStorage('commerce_domains_provider')->load($provider);
    if (!$entity || !$entity->hasCapability(CsvExportInterface::class)) {
      $this->logger()->error(dt('No provider @id with a CSV export.', ['@id' => $provider]));
      return self::EXIT_FAILURE;
    }
    $files = CsvExport::plugin($entity)->csvFiles();
    $file = $options['file'] ?: array_key_first($files);
    if (!isset($files[$file])) {
      $this->logger()->error(dt('@label has no file @file; choose one of: @files.', [
        '@label' => $entity->label(),
        '@file' => $file,
        '@files' => implode(', ', array_keys($files)),
      ]));
      return self::EXIT_FAILURE;
    }
    $this->output()->write(CsvExport::encode($this->export->rows($entity, $file)[$file]));
    return self::EXIT_SUCCESS;
  }

}

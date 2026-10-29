<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_import;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Runs the import form's batch, and keeps its report for the import page.
 *
 * The batch calls it as "Drupal\commerce_domains_import\ImportBatch:process",
 * so each request gets the service from the container.
 */
class ImportBatch {

  use StringTranslationTrait;

  // The private temporary store holding the last report and choices.
  public const STORE = 'commerce_domains_import';

  public function __construct(
    protected DomainImporter $importer,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected MessengerInterface $messenger,
    protected TimeInterface $time,
  ) {}

  /**
   * Imports one chunk of rows.
   *
   * @param \Drupal\commerce_domains_import\ImportRow[] $rows
   *   The rows.
   * @param \Drupal\commerce_domains_import\ImportOptions $options
   *   The import's choices.
   * @param int $total
   *   How many rows the whole import has.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function process(array $rows, ImportOptions $options, int $total, &$context): void {
    $report = $context['results']['report'] ?? new ImportReport($options->dryRun);
    $this->importer->import($rows, $options, $report);
    $context['results']['report'] = $report;
    $context['message'] = $this->t('@done of @total domains', ['@done' => $report->processed(), '@total' => $total]);
  }

  /**
   * Keeps the report for the import page, and sums it up.
   *
   * @param bool $success
   *   Whether every operation ran.
   * @param array $results
   *   The batch results, holding the report.
   */
  public function finished(bool $success, array $results): void {
    $report = $results['report'] ?? NULL;
    if (!$report instanceof ImportReport) {
      $this->messenger->addError($this->t('The import stopped before any domain was handled.'));
      return;
    }
    $this->tempStoreFactory->get(self::STORE)->set('report', [
      'time' => $this->time->getRequestTime(),
      'report' => $report,
    ]);
    $this->messenger->addStatus(self::summary($report));
    if (!$success) {
      $this->messenger->addError($this->t('The import stopped early; the report shows the domains handled before it did.'));
    }
    if ($report->errors) {
      $this->messenger->addWarning($this->formatPlural(count($report->errors), 'One row could not be imported; the report below says why.', '@count rows could not be imported; the report below says why.'));
    }
  }

  /**
   * Sums up a report in a sentence.
   */
  public static function summary(ImportReport $report): TranslatableMarkup {
    $args = [
      '@created' => count($report->created),
      '@updated' => count($report->updated),
      '@skipped' => count($report->skipped),
      '@errors' => count($report->errors),
    ];
    return $report->dryRun
      ? new TranslatableMarkup('Dry run, nothing saved: @created to create, @updated to update, @skipped to skip, @errors with errors.', $args)
      : new TranslatableMarkup('Created @created domains, updated @updated and skipped @skipped; @errors with errors.', $args);
  }

}

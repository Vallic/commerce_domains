<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\commerce_domains_owner\RenewalImporter;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imports the renewals paid from a CSV file, after a preview.
 */
final class RenewalImportForm extends FormBase {

  public function __construct(
    protected RenewalImporter $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(RenewalImporter::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_owner_renewal_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $rows = $form_state->get('rows');
    if (is_array($rows)) {
      return $this->buildPreview($form, $rows);
    }
    $form['help'] = [
      '#markup' => '<p>' . $this->t("One row per charge, with a header row naming the columns, in any order: <code>domain</code> (required), <code>date</code>, <code>amount</code> (optionally with its currency), <code>currency</code>, <code>years</code>, <code>type</code>, <code>registrar</code> (a provider ID or name) and <code>reference</code> (the order number). Dynadot's order history can be uploaded as it is.") . '</p><p>'
      . $this->t('A file with a type column keeps only its renewals, transfers and restores; registrations, auctions and discounts are passed over. Rows imported before add nothing.') . '</p>',
    ];
    $form['csv'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV file'),
      '#attributes' => ['accept' => '.csv,.txt,text/csv'],
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Or paste the rows'),
      '#rows' => 6,
      '#placeholder' => "domain,date,amount,years,registrar,reference\nexample.com,2026-10-30,10.88 USD,1,dynadot,27259600",
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Builds the preview: what is added, and the rows that can't be.
   */
  protected function buildPreview(array $form, array $rows): array {
    $plan = $this->importer->plan($rows);
    $summary = RenewalImporter::summary($plan);
    $table = [];
    foreach ($plan as $line => $entry) {
      if ($entry['passed'] || $entry['known']) {
        continue;
      }
      $table[] = [
        $line,
        $entry['domain'],
        $entry['problem'] !== NULL ? ['data' => $entry['problem'], 'class' => ['color-warning']] : RenewalImporter::describe($entry['values']),
      ];
    }
    $form['summary'] = [
      '#markup' => '<p>' . $this->t('@new to add, @known imported before, @problems left out, @passed not renewals.', [
        '@new' => $summary['new'],
        '@known' => $summary['known'],
        '@problems' => $summary['problems'],
        '@passed' => $summary['passed'],
      ]) . '</p>',
    ];
    $form['preview'] = [
      '#type' => 'table',
      '#header' => [$this->t('Line'), $this->t('Domain'), $this->t('Renewal')],
      '#rows' => $table,
    ];
    $form['actions'] = ['#type' => 'actions'];
    if ($summary['new']) {
      $form['actions']['import'] = [
        '#type' => 'submit',
        '#value' => $this->formatPlural($summary['new'], 'Import 1 renewal', 'Import @count renewals'),
        '#submit' => ['::import'],
        '#button_type' => 'primary',
      ];
    }
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start over'),
      '#submit' => ['::startOver'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (is_array($form_state->get('rows'))) {
      return;
    }
    $file = $this->upload();
    $content = $file ? (string) file_get_contents($file->getPathname()) : trim((string) $form_state->getValue('text'));
    if ($content === '') {
      $form_state->setErrorByName('csv', $this->t('Choose a CSV file, or paste the rows.'));
      return;
    }
    try {
      $rows = RenewalImporter::parse($content);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('csv', $e->getMessage());
      return;
    }
    if (!$rows) {
      $form_state->setErrorByName('csv', $this->t('There are no rows below the header.'));
      return;
    }
    $form_state->set('parsed', $rows);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('rows', $form_state->get('parsed'));
    $form_state->setRebuild();
  }

  /**
   * Imports the previewed rows, read again against the catalog as it is now.
   */
  public function import(array &$form, FormStateInterface $form_state): void {
    $count = $this->importer->apply($this->importer->plan((array) $form_state->get('rows')));
    $this->messenger()->addStatus($this->formatPlural($count, 'Imported 1 renewal.', 'Imported @count renewals.'));
    $form_state->setRedirect('entity.commerce_domains_renewal.collection');
  }

  /**
   * Goes back to the upload.
   */
  public function startOver(array &$form, FormStateInterface $form_state): void {
    $form_state->set('rows', NULL);
    $form_state->setRebuild();
  }

  /**
   * Returns the uploaded CSV file, if one arrived.
   */
  protected function upload(): ?UploadedFile {
    $files = $this->getRequest()->files->get('files', []);
    $file = is_array($files) ? ($files['csv'] ?? NULL) : NULL;
    return $file instanceof UploadedFile && $file->isValid() ? $file : NULL;
  }

}

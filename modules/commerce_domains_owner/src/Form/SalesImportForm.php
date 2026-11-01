<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\commerce_domains_owner\SalesImporter;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imports domain sales from a CSV file, after a preview of what changes.
 */
final class SalesImportForm extends FormBase {

  public function __construct(
    protected SalesImporter $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(SalesImporter::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_owner_sales_import';
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
      '#markup' => '<p>' . $this->t('One row per domain, with a header row naming the columns, in any order: <code>domain</code> (required), <code>sold</code> (the date, e.g. 2026-10-30), <code>price</code> (what the buyer paid, optionally with its currency), <code>currency</code>, <code>commission</code> (percent), <code>received</code> (what you got, which works out the commission), <code>sold_via</code> (a provider ID or name) and <code>lease_months</code>.') . '</p><p>'
      . $this->t("A domain not sold yet needs the date and the price, and goes off sale. For a domain already sold, the row's values replace what it has, and empty cells leave the rest as it is. Sales on this site come from their orders and are left out.") . '</p>',
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
      '#placeholder' => "domain,sold,price,commission,sold_via\nexample.com,2026-10-30,2500 USD,20,atom",
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
   * Builds the preview: what each row changes, and the rows left out.
   */
  protected function buildPreview(array $form, array $rows): array {
    $plan = $this->importer->plan($rows);
    $table = [];
    $changing = 0;
    foreach ($plan as $line => $entry) {
      if ($entry['problem'] === NULL && $entry['changes']) {
        $changing++;
      }
      $what = match (TRUE) {
        $entry['problem'] !== NULL => ['data' => $entry['problem'], 'class' => ['color-warning']],
        !$entry['changes'] => $this->t('Nothing changes.'),
        default => ['data' => ['#markup' => implode('<br>', array_map('htmlspecialchars', $this->importer->describe($entry['changes'])))]],
      };
      $table[] = [
        $line,
        $entry['product'] ? ['data' => $entry['product']->toLink($entry['domain'])->toRenderable()] : $entry['domain'],
        $entry['new'] && $entry['problem'] === NULL && $entry['changes'] ? $this->t('New sale') : '',
        $what,
      ];
    }
    $form['preview'] = [
      '#type' => 'table',
      '#header' => [$this->t('Line'), $this->t('Domain'), '', $this->t('Changes')],
      '#rows' => $table,
    ];
    $form['actions'] = ['#type' => 'actions'];
    if ($changing) {
      $form['actions']['import'] = [
        '#type' => 'submit',
        '#value' => $this->formatPlural($changing, 'Import 1 sale', 'Import @count sales'),
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
      $rows = SalesImporter::parse($content);
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
    $this->messenger()->addStatus($this->formatPlural($count, 'Imported the sale of 1 domain.', 'Imported the sales of @count domains.'));
    $form_state->setRedirect('commerce_domains_owner.profit');
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

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\RenewalCostImporter;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fills the renewal costs from a file or a price list, after a preview.
 */
final class RenewalCostImportForm extends FormBase {

  // The source that reads a file, beside those of the importer.
  protected const FILE = 'file';

  public function __construct(
    protected RenewalCostImporter $importer,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(RenewalCostImporter::class),
      $container->get('commerce_price.currency_formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_owner_renewal_cost_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $plan = $form_state->get('plan');
    if (is_array($plan)) {
      return $this->buildPreview($form, $plan, (array) $form_state->get('unpriced'));
    }
    $form['source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Fill from'),
      '#options' => [self::FILE => $this->t('A CSV file, or pasted rows')] + $this->importer->sources(),
      '#default_value' => self::FILE,
      '#required' => TRUE,
    ];
    $file = [':input[name="source"]' => ['value' => self::FILE]];
    $form['help'] = [
      '#type' => 'container',
      '#states' => ['visible' => $file],
      'text' => [
        '#markup' => '<p>' . $this->t('One extension a row, with a header row naming the columns, in any order: <code>extension</code> (required), <code>renewal</code> (the amount, optionally with its currency), <code>currency</code> and <code>registrar</code> (a provider ID or name; leave it out for the general cost). The costs in the file replace those set.') . '</p>',
      ],
    ];
    $form['csv'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV file'),
      '#attributes' => ['accept' => '.csv,.txt,text/csv'],
      '#states' => ['visible' => $file],
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Or paste the rows'),
      '#rows' => 6,
      '#placeholder' => "extension,renewal,currency,registrar\ncom,10.88,USD,dynadot\nco.uk,7,GBP,",
      '#states' => ['visible' => $file],
    ];
    $form['replace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace the general costs already set'),
      '#description' => $this->t("Porkbun's prices are one registrar's, so by default they only fill the gaps."),
      '#states' => ['visible' => [':input[name="source"]' => ['value' => RenewalCostImporter::PORKBUN_LIST]]],
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
   * Builds the preview: what changes, and the rows that can't be read.
   */
  protected function buildPreview(array $form, array $plan, array $unpriced): array {
    $summary = RenewalCostImporter::summary($plan);
    $table = [];
    foreach ($plan as $line => $entry) {
      if (in_array($entry['status'], ['same', 'kept'], TRUE)) {
        continue;
      }
      $table[] = [
        $entry['tld'] ?: $line,
        (string) $entry['registrar'] ?: $this->t('Any'),
        $entry['price'] ? $this->money($entry['price']) : '',
        match ($entry['status']) {
          'new' => $this->t('New'),
          'replaced' => $this->t('Was @amount', ['@amount' => $this->money($entry['current'])]),
          default => [
            'data' => $this->t('Line @line: @problem', ['@line' => $line, '@problem' => $entry['problem']]),
            'class' => ['color-warning'],
          ],
        },
      ];
    }
    $form['summary'] = [
      '#markup' => '<p>' . $this->t('@new to add, @replaced to change, @same unchanged, @kept kept as set, @problems left out.', [
        '@new' => $summary['new'],
        '@replaced' => $summary['replaced'],
        '@same' => $summary['same'],
        '@kept' => $summary['kept'],
        '@problems' => $summary['problem'],
      ]) . '</p>',
    ];
    if ($unpriced) {
      $form['unpriced'] = [
        '#markup' => '<p>' . $this->t('Not on the price list, so enter these yourself: @list.', ['@list' => implode(', ', $unpriced)]) . '</p>',
      ];
    }
    $form['preview'] = [
      '#type' => 'table',
      '#header' => [$this->t('Extension'), $this->t('Registrar'), $this->t('Cost a year'), ''],
      '#rows' => $table,
      '#empty' => $this->t('Nothing to change.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    if ($summary['new'] || $summary['replaced']) {
      $form['actions']['import'] = [
        '#type' => 'submit',
        '#value' => $this->formatPlural($summary['new'] + $summary['replaced'], 'Save 1 cost', 'Save @count costs'),
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
    if (is_array($form_state->get('plan'))) {
      return;
    }
    $source = (string) $form_state->getValue('source');
    if ($source !== self::FILE) {
      try {
        $list = $this->importer->planList($source, (bool) $form_state->getValue('replace'));
        $form_state->set('planned', $list);
      }
      catch (ProviderException $e) {
        $form_state->setErrorByName('source', $this->t('The prices could not be read: @error', ['@error' => $e->getMessage()]));
      }
      return;
    }
    $file = $this->upload();
    $content = $file ? (string) file_get_contents($file->getPathname()) : trim((string) $form_state->getValue('text'));
    if ($content === '') {
      $form_state->setErrorByName('csv', $this->t('Choose a CSV file, or paste the rows.'));
      return;
    }
    try {
      $rows = RenewalCostImporter::parse($content);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('csv', $e->getMessage());
      return;
    }
    if (!$rows) {
      $form_state->setErrorByName('csv', $this->t('There are no rows below the header.'));
      return;
    }
    $form_state->set('planned', ['plan' => $this->importer->planRows($rows), 'unpriced' => []]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $planned = (array) $form_state->get('planned');
    $form_state->set('plan', $planned['plan'] ?? []);
    $form_state->set('unpriced', $planned['unpriced'] ?? []);
    $form_state->setRebuild();
  }

  /**
   * Saves the previewed costs.
   */
  public function import(array &$form, FormStateInterface $form_state): void {
    $count = $this->importer->apply((array) $form_state->get('plan'));
    $this->messenger()->addStatus($this->formatPlural($count, 'Saved 1 renewal cost.', 'Saved @count renewal costs.'));
    $form_state->setRedirect('entity.commerce_domains_renewal_cost.collection');
  }

  /**
   * Goes back to the start.
   */
  public function startOver(array &$form, FormStateInterface $form_state): void {
    $form_state->set('plan', NULL);
    $form_state->setRebuild();
  }

  /**
   * Formats an amount.
   */
  protected function money(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
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

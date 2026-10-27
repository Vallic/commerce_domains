<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\CsvExportInterface;
use Drupal\commerce_domains_marketplace\CsvExport;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows a provider's CSV files and downloads them.
 *
 * The same as `drush commerce-domains:csv-export`. Nothing is sent to the
 * provider; the file is for its own upload page.
 */
final class CsvExportForm extends FormBase {

  // Rows shown of each file.
  protected const PREVIEW_ROWS = 20;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CsvExport $export,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(CsvExport::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_provider_csv_export';
  }

  /**
   * Allows the page for providers that export CSV files.
   */
  public static function access(ProviderInterface $commerce_domains_provider): AccessResultInterface {
    return AccessResult::allowedIf($commerce_domains_provider->hasCapability(CsvExportInterface::class))
      ->addCacheableDependency($commerce_domains_provider);
  }

  /**
   * The page title.
   */
  public function title(ProviderInterface $commerce_domains_provider): string {
    return (string) $this->t('@label CSV export', ['@label' => $commerce_domains_provider->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProviderInterface $commerce_domains_provider = NULL): array {
    // Admin routes load config entities without overrides, and a site may
    // set the price factor in settings.php: load it again with them.
    $form_state->set('provider', $commerce_domains_provider->id());
    $provider = $this->provider($form_state);
    $files = CsvExport::plugin($provider)->csvFiles();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('The domains for sale, priced for @label, in the file its upload page takes. Nothing is sent to @label.', ['@label' => $provider->label()]) . '</p>',
    ];
    foreach ($this->export->rows($provider) as $file => $rows) {
      $header = array_shift($rows);
      $form[$file] = [
        '#type' => 'details',
        '#title' => count($files) > 1 ? $files[$file] : $this->t('Listings'),
        '#open' => TRUE,
      ];
      $form[$file]['preview'] = [
        '#type' => 'table',
        '#caption' => count($rows) > self::PREVIEW_ROWS
          ? $this->t('@count domains; the first @shown:', ['@count' => count($rows), '@shown' => self::PREVIEW_ROWS])
          : $this->formatPlural(count($rows), '1 domain.', '@count domains.'),
        '#header' => $header,
        '#rows' => array_slice($rows, 0, self::PREVIEW_ROWS),
        '#empty' => $this->t('No domains for sale go in this file.'),
      ];
      $form[$file]['download'] = [
        '#type' => 'submit',
        '#value' => $this->t('Download @file', ['@file' => CsvExport::filename($provider, $file)]),
        '#name' => 'download_' . $file,
        '#file' => $file,
        '#access' => (bool) $rows,
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $provider = $this->provider($form_state);
    $file = (string) $form_state->getTriggeringElement()['#file'];
    $response = new Response(CsvExport::encode($this->export->rows($provider, $file)[$file]));
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . CsvExport::filename($provider, $file) . '"');
    $form_state->setResponse($response);
  }

  /**
   * Loads the form's provider afresh, with its overrides.
   */
  protected function provider(FormStateInterface $form_state): ProviderInterface {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface */
    return $this->entityTypeManager->getStorage('commerce_domains_provider')->load($form_state->get('provider'));
  }

}

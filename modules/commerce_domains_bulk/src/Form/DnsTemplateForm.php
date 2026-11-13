<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Form;

use Drupal\commerce_domains\DnsTemplateApplier;
use Drupal\commerce_domains\ProviderException;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Gives the selected domains the records of a DNS template, at once.
 *
 * Each domain is written at its registrar without a review of its own; the
 * zone there before is kept, and can be put back from its DNS tab.
 */
final class DnsTemplateForm extends BulkFormBase {

  // Domains read and written at once in a batch step: one API call or two
  // each, against registrar rate limits.
  protected const BATCH_SIZE = 5;

  /**
   * {@inheritdoc}
   */
  protected function actionId(): string {
    return 'commerce_domains_dns_template';
  }

  /**
   * {@inheritdoc}
   */
  protected function edit(): string {
    return 'dns_template';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->products), 'Apply a DNS template to 1 domain?', 'Apply a DNS template to @count domains?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The records are written at each registrar at once, without a review per domain. The zone each had before is kept, and can be put back from its DNS tab. Domains at a registrar whose API does not edit DNS, with their nameservers elsewhere, or sold, are left as they are.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Apply');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $templates = $this->entityTypeManager->getStorage('commerce_domains_dns_template')->loadMultiple();
    if (!$templates) {
      $this->messenger()->addWarning($this->t('There are no DNS templates yet. <a href=":url">Add one</a> first.', [
        ':url' => Url::fromRoute('entity.commerce_domains_dns_template.add_form')->toString(),
      ]));
    }
    $form['template_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Template'),
      '#options' => array_map(static fn($template) => $template->label(), $templates),
      '#required' => TRUE,
    ];
    $form['template_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('The records there now'),
      '#options' => [
        DnsTemplateApplier::ADD => $this->t("Keep them, and add the template's that are missing"),
        DnsTemplateApplier::REPLACE => $this->t("Remove them: only the template's records stay"),
      ],
      '#description' => $this->t('Removing them also removes marketplace verification records and forwards.'),
      '#default_value' => DnsTemplateApplier::ADD,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function options(FormStateInterface $form_state): array {
    return [
      'template' => (string) $form_state->getValue('template_id'),
      'mode' => $form_state->getValue('template_mode') === DnsTemplateApplier::REPLACE ? DnsTemplateApplier::REPLACE : DnsTemplateApplier::ADD,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Applying the DNS template'))
      ->setFinishCallback([self::class, 'batchFinished']);
    $options = $this->options($form_state);
    foreach (array_chunk(array_keys($this->products), self::BATCH_SIZE) as $ids) {
      $batch->addOperation([self::class, 'batchOperation'], [$ids, $options['template'], $options['mode']]);
    }
    batch_set($batch->toArray());
    $this->tempStore()->delete($this->actionId());
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Batch operation: applies the template to some domains.
   *
   * @param int[] $product_ids
   *   The domains' product IDs.
   * @param string $template_id
   *   The template.
   * @param string $mode
   *   DnsTemplateApplier::ADD or ::REPLACE.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function batchOperation(array $product_ids, string $template_id, string $mode, array|\ArrayAccess &$context): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    /** @var \Drupal\commerce_domains\Entity\DnsTemplateInterface|null $template */
    $template = $entity_type_manager->getStorage('commerce_domains_dns_template')->load($template_id);
    if (!$template) {
      return;
    }
    $applier = \Drupal::service(DnsTemplateApplier::class);
    $account = \Drupal::currentUser();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($entity_type_manager->getStorage('commerce_product')->loadMultiple($product_ids) as $product) {
      if (!$product->access('update', $account)) {
        $result = DnsTemplateApplier::SKIPPED;
      }
      else {
        try {
          $result = $applier->apply($product, $template, $mode);
        }
        catch (ProviderException $e) {
          $result = 'failed';
          $context['results']['failed_domains'][] = $product->label();
          \Drupal::logger('commerce_domains')->warning('@domain: DNS template @template not applied: @error', [
            '@domain' => $product->label(),
            '@template' => $template->label(),
            '@error' => $e->getMessage(),
          ]);
        }
      }
      $context['results'][$result] = ($context['results'][$result] ?? 0) + 1;
    }
  }

  /**
   * Batch finished callback: says what was done.
   */
  public static function batchFinished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The batch stopped before every domain was done. Check the log, and try again for the rest.'));
    }
    if ($count = $results[DnsTemplateApplier::CHANGED] ?? 0) {
      $messenger->addStatus(new PluralTranslatableMarkup($count, 'Changed the DNS of 1 domain.', 'Changed the DNS of @count domains.'));
    }
    if ($count = $results[DnsTemplateApplier::UNCHANGED] ?? 0) {
      $messenger->addStatus(new PluralTranslatableMarkup($count, '1 domain had the records already.', '@count domains had the records already.'));
    }
    if ($count = $results[DnsTemplateApplier::ELSEWHERE] ?? 0) {
      $messenger->addWarning(new PluralTranslatableMarkup($count, "1 domain was left as it is: its nameservers aren't its registrar's.", "@count domains were left as they are: their nameservers aren't their registrar's."));
    }
    if ($count = $results[DnsTemplateApplier::SKIPPED] ?? 0) {
      $messenger->addWarning(new PluralTranslatableMarkup($count, "1 domain was skipped: sold, or at a registrar whose API doesn't edit DNS.", "@count domains were skipped: sold, or at a registrar whose API doesn't edit DNS."));
    }
    if ($results['failed_domains'] ?? []) {
      $messenger->addError(new TranslatableMarkup('The registrar refused, or could not be read, for @domains. The log says why.', ['@domains' => implode(', ', $results['failed_domains'])]));
    }
  }

}

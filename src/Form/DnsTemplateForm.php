<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Entity\DnsTemplate;
use Drupal\commerce_domains\Entity\DnsTemplateInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DnsRecordsInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds and edits DNS templates.
 *
 * The record types offered are the standard ones and those of the
 * registrars whose API edits DNS, such as Dynadot's URL forwarding; a
 * registrar without one of them leaves its records out.
 */
class DnsTemplateForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\commerce_domains\Entity\DnsTemplateInterface $template */
    $template = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $template->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $template->id(),
      '#machine_name' => ['exists' => [DnsTemplate::class, 'load']],
      '#disabled' => !$template->isNew(),
    ];

    if (!$form_state->has('rows')) {
      $rows = $template->getRecords() ?: [(new DnsRecord('A', '@', ''))->toArray()];
      $form_state->set('rows', $rows);
    }
    $rows = $form_state->get('rows');
    $types = $this->types();
    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('Up to @max records. Hosts are relative to the domain: @ for the domain itself, www, mail, _dmarc. {domain} in a value becomes the domain, as in https://{domain}/. Leave TTL empty for the registrar default; a row with no value is dropped.', ['@max' => DnsTemplateInterface::MAX_RECORDS]),
    ];
    $form['records'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Type'),
        $this->t('Host'),
        $this->t('Value'),
        $this->t('Priority'),
        $this->t('TTL'),
        $this->t('Remove'),
      ],
      '#tree' => TRUE,
    ];
    foreach ($rows as $i => $row) {
      $options = $types;
      if (($row['type'] ?? '') !== '' && !isset($options[$row['type']])) {
        $options[$row['type']] = $row['type'];
      }
      $form['records'][$i] = [
        'type' => [
          '#type' => 'select',
          '#title' => $this->t('Type'),
          '#title_display' => 'invisible',
          '#options' => $options,
          '#default_value' => ($row['type'] ?? '') ?: 'A',
        ],
        'host' => [
          '#type' => 'textfield',
          '#title' => $this->t('Host'),
          '#title_display' => 'invisible',
          '#default_value' => $row['host'] ?? '@',
          '#size' => 18,
        ],
        'value' => [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#title_display' => 'invisible',
          '#default_value' => $row['value'] ?? '',
          '#size' => 48,
          '#maxlength' => 2048,
        ],
        'priority' => [
          '#type' => 'number',
          '#title' => $this->t('Priority'),
          '#title_display' => 'invisible',
          '#default_value' => $row['priority'] ?? '',
          '#min' => 0,
          '#max' => 65535,
          '#size' => 5,
        ],
        'ttl' => [
          '#type' => 'number',
          '#title' => $this->t('TTL'),
          '#title_display' => 'invisible',
          '#default_value' => $row['ttl'] ?? '',
          '#min' => 60,
          '#max' => 604800,
          '#size' => 7,
        ],
        'remove' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Remove'),
          '#title_display' => 'invisible',
        ],
      ];
    }
    $form['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add a record'),
      '#submit' => ['::addRecord'],
      // Only the fields' own checks: the template need not be whole yet.
      '#validate' => [],
      '#limit_validation_errors' => [['records']],
      '#access' => count($rows) < DnsTemplateInterface::MAX_RECORDS,
    ];
    return $form;
  }

  /**
   * Adds an empty row.
   */
  public function addRecord(array &$form, FormStateInterface $form_state): void {
    $rows = $this->enteredRows($form_state, TRUE);
    if (count($rows) < DnsTemplateInterface::MAX_RECORDS) {
      $rows[] = (new DnsRecord('A', '@', ''))->toArray();
    }
    $form_state->set('rows', $rows)->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $rows = $this->enteredRows($form_state);
    if (count($rows) > DnsTemplateInterface::MAX_RECORDS) {
      $form_state->setErrorByName('records', $this->t('A template holds up to @max records.', ['@max' => DnsTemplateInterface::MAX_RECORDS]));
    }
    if (!$rows) {
      $form_state->setErrorByName('records', $this->t('Add at least one record.'));
    }
    foreach ($rows as $i => $row) {
      // Checked as it would be for a domain.
      $row['value'] = str_replace(DnsTemplateInterface::PLACEHOLDER, 'example.com', (string) $row['value']);
      foreach (DnsRecord::fromArray($row)->problems() as $problem) {
        $form_state->setErrorByName("records][$i][value", $problem);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    if (!$entity instanceof DnsTemplateInterface) {
      return;
    }
    foreach (['label', 'id'] as $key) {
      if ($form_state->hasValue($key)) {
        $entity->set($key, $form_state->getValue($key));
      }
    }
    $rows = $this->enteredRows($form_state);
    if (count($rows) <= DnsTemplateInterface::MAX_RECORDS) {
      $entity->setRecords($rows);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Added the DNS template %label.', $args)
      : $this->t('Saved the DNS template %label.', $args));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Returns the rows as entered: without removed ones, nor empty ones.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param bool $keep_empty
   *   Whether to keep rows with no value, as when adding a row.
   *
   * @return array<int, array<string, mixed>>
   *   Rows keyed as the table has them.
   */
  protected function enteredRows(FormStateInterface $form_state, bool $keep_empty = FALSE): array {
    // An empty table submits an empty string, not an empty array.
    $values = $form_state->getValue('records');
    $rows = [];
    foreach (is_array($values) ? $values : [] as $i => $row) {
      if (!is_array($row) || !empty($row['remove'])) {
        continue;
      }
      $record = DnsRecord::fromArray($row);
      if ($record->value === '' && !$keep_empty) {
        continue;
      }
      $rows[(int) $i] = $record->toArray();
    }
    return $rows;
  }

  /**
   * Returns the record types offered: the standard ones, then registrars'.
   *
   * @return array<string, string|\Stringable>
   *   Labels keyed by type.
   */
  protected function types(): array {
    $types = array_combine(array_keys(DnsRecord::TYPES), array_keys(DnsRecord::TYPES));
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface $provider */
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      $plugin = $provider->getPlugin();
      if ($plugin instanceof DnsRecordsInterface) {
        $types += $plugin->dnsRecordTypes();
      }
    }
    return $types;
  }

}

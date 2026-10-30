<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Plugin\Field\FieldWidget;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\Plugin\Field\FieldWidget\TimestampDatetimeWidget;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A date and time that stays empty when left blank.
 *
 * Core's widget saves a blank date as the time of saving, which would mark
 * a domain sold, or bought, whenever its product is saved.
 */
#[FieldWidget(
  id: 'commerce_domains_optional_timestamp',
  label: new TranslatableMarkup('Date and time, optional'),
  field_types: ['timestamp'],
)]
class OptionalTimestampWidget extends TimestampDatetimeWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    // Not the parent's note that blank means the time of saving.
    $element['value']['#description'] = $this->fieldDefinition->getDescription();
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $items = [];
    foreach ($values as $item) {
      $date = $item['value'] ?? NULL;
      if (is_array($date)) {
        $date = $date['object'] ?? NULL;
      }
      if ($date instanceof DrupalDateTime) {
        $items[] = ['value' => $date->getTimestamp()];
      }
    }
    return $items;
  }

}

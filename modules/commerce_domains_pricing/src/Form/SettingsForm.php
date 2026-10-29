<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_pricing\Form;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains_pricing\PriceRule;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for domain pricing: the rules, rounding and currency prices.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Catalog $catalog,
    // Whether Commerce Exchanger can convert prices.
    protected bool $converts,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get(Catalog::class),
      $container->has('commerce_exchanger.calculate'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_pricing_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [PriceRules::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(PriceRules::CONFIG);
    $currencies = $this->currencies();
    /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');
    $default_currency = $store_storage->loadDefault()?->getDefaultCurrencyCode() ?? array_key_first($currencies);

    $form['pricing_rules'] = [
      '#type' => 'details',
      '#title' => $this->t('Pricing rules'),
      '#open' => TRUE,
      '#description' => $this->t('A domain saved without a price gets the price of the first rule that matches it, from the top; a domain with a price keeps it. Empty conditions match any domain. Words are counted from capitals and hyphens in the name as written: LearnPhpOnline.com has three. To reprice domains by the rules, select them on the Domains overview and choose <em>Apply pricing rules</em>.'),
    ];
    $form['pricing_rules']['rules'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Extensions'),
        $this->t('Label length'),
        $this->t('Words'),
        $this->t('Atom premium'),
        $this->t('Price'),
        $this->t('Remove'),
        $this->t('Weight'),
      ],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'commerce-domains-rule-weight',
        ],
      ],
      // The rows hold the values; this keeps the stored rules out of them.
      '#default_value' => [],
      '#config_target' => new ConfigTarget(PriceRules::CONFIG, 'rules', toConfig: [self::class, 'rulesToConfig']),
    ];
    $rules = [];
    foreach ($config->get('rules') ?? [] as $values) {
      try {
        $rules[] = PriceRule::fromConfig($values);
      }
      catch (\InvalidArgumentException | \TypeError) {
        // A rule without a valid price, as only an edited config file has;
        // saving the form drops it.
      }
    }
    // An empty row adds a rule.
    $rules[] = NULL;
    foreach ($rules as $delta => $rule) {
      $form['pricing_rules']['rules'][$delta] = $this->ruleRow($rule, $delta, count($rules), $currencies, $default_currency);
    }

    $form['rounding'] = [
      '#type' => 'details',
      '#title' => $this->t('Rounding'),
      '#open' => TRUE,
      '#description' => $this->t("For prices in other currencies, and prices repriced by a factor. An amount takes the step with the lowest limit at or above it, and is rounded to a multiple, less an amount: up to a multiple of 100, less 1, turns 1,234 into 1,299. Amounts above every limit are only rounded to the currency's decimals. The same steps apply in every currency."),
    ];
    $form['rounding']['whole_units'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Drop the cents first'),
      '#description' => $this->t('1,200.50 counts as 1,200, also when the step is chosen.'),
      '#config_target' => PriceRules::CONFIG . ':rounding.whole_units',
    ];
    $form['rounding']['steps'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Up to'),
        $this->t('Round'),
        $this->t('To a multiple of'),
        $this->t('Then subtract'),
        $this->t('At most'),
        $this->t('Remove'),
      ],
      '#default_value' => [],
      '#config_target' => new ConfigTarget(
        PriceRules::CONFIG,
        'rounding.steps',
        toConfig: [self::class, 'stepsToConfig'],
      ),
    ];
    $steps = array_values($config->get('rounding.steps') ?? []);
    $steps[] = NULL;
    foreach ($steps as $delta => $step) {
      $form['rounding']['steps'][$delta] = $this->stepRow($step);
    }

    $form['currency_prices'] = [
      '#type' => 'details',
      '#title' => $this->t('Prices in other currencies'),
      '#open' => TRUE,
      '#description' => $this->t("On each save of a domain, its price is converted into each of these currencies, its own included, at Commerce Exchanger's rates and rounded with the steps above, then written where set below. Currencies without a rate keep what they have. Syncing saves, such as migrations, keep the stored prices: the price list then follows the price fields."),
    ];
    if (!$this->converts) {
      $form['currency_prices']['exchanger'] = [
        '#markup' => '<p>' . $this->t("Commerce Exchanger is not installed, so only each domain's own currency gets a price.") . '</p>',
      ];
    }
    $form['currency_prices']['currencies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Currencies'),
      '#options' => $currencies,
      '#description' => $this->t('With none checked, every enabled currency.'),
      '#config_target' => new ConfigTarget(PriceRules::CONFIG, 'currencies', toConfig: [self::class, 'checkedToConfig']),
    ];
    if ($this->entityTypeManager->hasDefinition('commerce_pricelist')) {
      $form['currency_prices']['price_list'] = [
        '#type' => 'select',
        '#title' => $this->t('Price list'),
        '#options' => $this->priceLists(),
        '#empty_option' => $this->t('- None -'),
        '#description' => $this->t("Each domain's items in this list follow its prices, one per currency at a quantity of one, and its items in other currencies are removed: choose a list kept for these prices. A disabled list keeps them off the storefront, and marketplaces can still use it."),
        '#config_target' => new ConfigTarget(PriceRules::CONFIG, 'price_list', toConfig: [self::class, 'emptyToNull']),
      ];
    }
    $form['currency_prices']['field_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Price fields'),
      '#description' => $this->t('A variation with a price field named this plus the currency code in lower case, such as field_price_usd, gets the price in that currency there. Leave empty for no fields.'),
      '#placeholder' => 'field_price_',
      '#config_target' => PriceRules::CONFIG . ':field_prefix',
    ];
    $form['currency_prices']['update'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update every domain now'),
      '#description' => $this->t('Saves each domain once, in batches, so its prices in other currencies follow these settings. Otherwise each domain follows on its next save.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $types = $this->catalog->productTypes();
    if (!$form_state->getValue('update') || !$types) {
      return;
    }
    $ids = $this->entityTypeManager->getStorage('commerce_product_variation')->getQuery()
      ->accessCheck(FALSE)
      ->condition('product_id.entity:commerce_product.type', $types, 'IN')
      ->sort('variation_id')
      ->execute();
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Updating the prices in other currencies'))
      ->setFinishCallback([self::class, 'batchFinished']);
    foreach (array_chunk(array_values($ids), 50) as $chunk) {
      $batch->addOperation([self::class, 'batchUpdate'], [$chunk]);
    }
    batch_set($batch->toArray());
  }

  /**
   * Batch operation: saves domain variations, which updates their prices.
   *
   * @param int[] $ids
   *   The variation IDs.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function batchUpdate(array $ids, array|\ArrayAccess &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
    foreach ($storage->loadMultiple($ids) as $variation) {
      $variation->save();
    }
    $storage->resetCache($ids);
    $context['results']['saved'] = ($context['results']['saved'] ?? 0) + count($ids);
  }

  /**
   * Batch finished callback: says how many domains were updated.
   */
  public static function batchFinished(bool $success, array $results): void {
    $count = $results['saved'] ?? 0;
    if ($success) {
      \Drupal::messenger()->addStatus(new PluralTranslatableMarkup($count, 'Updated the prices of 1 domain.', 'Updated the prices of @count domains.'));
    }
    else {
      \Drupal::messenger()->addError(new TranslatableMarkup('Not every domain could be updated. Check the log, and save the settings again to retry.'));
    }
  }

  /**
   * Builds a row of the rules table.
   */
  protected function ruleRow(?PriceRule $rule, int $delta, int $count, array $currencies, ?string $default_currency): array {
    $row = [
      '#attributes' => ['class' => ['draggable']],
      '#weight' => $delta,
    ];
    $row['extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extensions'),
      '#title_display' => 'invisible',
      '#default_value' => implode(', ', array_map([self::class, 'unicode'], $rule->extensions ?? [])),
      '#placeholder' => $this->t('Any, or com, co.uk'),
      '#size' => 16,
    ];
    $row['length'] = $this->range($this->t('Label length'), $rule?->minLength, $rule?->maxLength);
    $row['words'] = $this->range($this->t('Words'), $rule?->minWords, $rule?->maxWords);
    $row['atom_premium'] = [
      '#type' => 'select',
      '#title' => $this->t('Atom premium'),
      '#title_display' => 'invisible',
      '#options' => [
        '' => $this->t('Either'),
        '1' => $this->t('Premium only'),
        '0' => $this->t('Not premium'),
      ],
      '#default_value' => match ($rule?->atomPremium) {
        TRUE => '1',
        FALSE => '0',
        NULL => '',
      },
    ];
    // A rule keeps its currency while that is disabled.
    $codes = array_unique([...array_keys($currencies), ...($rule ? [$rule->price->getCurrencyCode()] : [])]);
    $row['price'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
      'number' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#title_display' => 'invisible',
        '#min' => 0,
        '#step' => 'any',
        '#size' => 8,
        '#default_value' => $rule?->price->getNumber(),
      ],
      'currency_code' => [
        '#type' => 'select',
        '#title' => $this->t('Currency'),
        '#title_display' => 'invisible',
        '#options' => array_combine($codes, $codes),
        '#default_value' => $rule?->price->getCurrencyCode() ?? $default_currency,
      ],
    ];
    $row['remove'] = $rule ? [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove'),
      '#title_display' => 'invisible',
    ] : ['#markup' => ''];
    $row['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight'),
      '#title_display' => 'invisible',
      '#default_value' => $delta,
      '#delta' => max(10, $count),
      '#attributes' => ['class' => ['commerce-domains-rule-weight']],
    ];
    return $row;
  }

  /**
   * Builds a "from, to" pair of number fields for a rule.
   */
  protected function range(TranslatableMarkup $title, ?int $min, ?int $max): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
      'min' => [
        '#type' => 'number',
        '#title' => $this->t('@title from', ['@title' => $title]),
        '#title_display' => 'invisible',
        '#min' => 1,
        '#size' => 3,
        '#placeholder' => $this->t('From'),
        '#default_value' => $min,
      ],
      'max' => [
        '#type' => 'number',
        '#title' => $this->t('@title to', ['@title' => $title]),
        '#title_display' => 'invisible',
        '#min' => 1,
        '#size' => 3,
        '#placeholder' => $this->t('To'),
        '#default_value' => $max,
      ],
    ];
  }

  /**
   * Builds a row of the rounding steps table.
   */
  protected function stepRow(?array $step): array {
    $number = fn(TranslatableMarkup $title, $value, array $extra = []) => $extra + [
      '#type' => 'number',
      '#title' => $title,
      '#title_display' => 'invisible',
      '#min' => 0,
      '#step' => 'any',
      '#size' => 8,
      '#default_value' => $value,
    ];
    return [
      'up_to' => $number($this->t('Up to'), $step['up_to'] ?? NULL, ['#placeholder' => $this->t('No limit')]),
      'method' => [
        '#type' => 'select',
        '#title' => $this->t('Round'),
        '#title_display' => 'invisible',
        '#options' => [
          'up' => $this->t('Up'),
          'down' => $this->t('Down'),
          'nearest' => $this->t('To the nearest'),
        ],
        '#default_value' => $step['method'] ?? 'up',
      ],
      'multiple' => $number($this->t('To a multiple of'), $step['multiple'] ?? NULL),
      'subtract' => $number($this->t('Then subtract'), $step ? ($step['subtract'] ?? 0) : NULL, ['#placeholder' => '0']),
      'max' => $number($this->t('At most'), $step['max'] ?? NULL, ['#placeholder' => $this->t('No limit')]),
      'remove' => $step ? [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
        '#title_display' => 'invisible',
      ] : ['#markup' => ''],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->getValue('rules') ?: [] as $delta => $row) {
      $element = $form['pricing_rules']['rules'][$delta];
      if (!empty($row['remove'])) {
        continue;
      }
      $number = trim((string) $row['price']['number']);
      $conditions = trim((string) $row['extensions']) !== '' || $row['atom_premium'] !== ''
        || implode('', $row['length']) !== '' || implode('', $row['words']) !== '';
      if ($number === '' && $conditions) {
        $form_state->setError($element['price']['number'], isset($element['remove']['#type'])
          ? $this->t('Enter a price for the rule, or tick Remove.')
          : $this->t('Enter a price for the new rule, or clear its row.'));
      }
      foreach (self::extensions((string) $row['extensions']) as $extension) {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $extension)) {
          $form_state->setError($element['extensions'], $this->t('%extension is not an extension.', ['%extension' => $extension]));
        }
      }
      $messages = [
        'length' => $this->t('The longest label must be at least the shortest.'),
        'words' => $this->t('The most words must be at least the fewest.'),
      ];
      foreach ($messages as $key => $message) {
        if ($row[$key]['min'] !== '' && $row[$key]['max'] !== '' && (int) $row[$key]['min'] > (int) $row[$key]['max']) {
          $form_state->setError($element[$key]['max'], $message);
        }
      }
    }

    $limits = [];
    foreach ($form_state->getValue('steps') ?: [] as $delta => $row) {
      $element = $form['rounding']['steps'][$delta];
      if (!empty($row['remove'])) {
        continue;
      }
      if ((string) $row['multiple'] === '') {
        if ((string) $row['up_to'] !== '' || (string) $row['max'] !== '' || (string) $row['subtract'] !== '') {
          $form_state->setError($element['multiple'], $this->t('Enter the multiple to round to.'));
        }
        continue;
      }
      if ((float) $row['multiple'] <= 0) {
        $form_state->setError($element['multiple'], $this->t('The multiple must be more than 0.'));
      }
      $limit = (string) $row['up_to'] === '' ? 'none' : (string) (float) $row['up_to'];
      if (isset($limits[$limit])) {
        $form_state->setError($element['up_to'], $limit === 'none'
          ? $this->t('Only one step can be without a limit.')
          : $this->t('Two steps have the same limit.'));
      }
      $limits[$limit] = TRUE;
    }

    // The schema checks come after these, which point at the right field.
    if (!$form_state->getErrors()) {
      parent::validateForm($form, $form_state);
    }
  }

  /**
   * Turns the rows of the rules table into the rules, in order.
   *
   * Rows without a price, or ticked for removal, are left out.
   */
  public static function rulesToConfig(mixed $rows): array {
    $rows = is_array($rows) ? $rows : [];
    uasort($rows, fn(array $a, array $b) => (int) ($a['weight'] ?? 0) <=> (int) ($b['weight'] ?? 0));
    $integer = fn($value): ?int => is_numeric($value) ? (int) $value : NULL;
    $rules = [];
    foreach ($rows as $row) {
      $number = trim((string) ($row['price']['number'] ?? ''));
      if (!empty($row['remove']) || !is_numeric($number)) {
        continue;
      }
      $rules[] = [
        'extensions' => self::extensions((string) ($row['extensions'] ?? '')),
        'min_length' => $integer($row['length']['min'] ?? NULL),
        'max_length' => $integer($row['length']['max'] ?? NULL),
        'min_words' => $integer($row['words']['min'] ?? NULL),
        'max_words' => $integer($row['words']['max'] ?? NULL),
        'atom_premium' => match ((string) ($row['atom_premium'] ?? '')) {
          '1' => TRUE,
          '0' => FALSE,
          default => NULL,
        },
        'price' => [
          'number' => $number,
          'currency_code' => (string) $row['price']['currency_code'],
        ],
      ];
    }
    return $rules;
  }

  /**
   * Turns the rows of the steps table into the rounding steps.
   *
   * Rows without a multiple, or ticked for removal, are left out.
   */
  public static function stepsToConfig(mixed $rows): array {
    $float = fn($value): ?float => is_numeric($value) ? (float) $value : NULL;
    $steps = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      if (!empty($row['remove']) || !is_numeric($row['multiple'] ?? NULL)) {
        continue;
      }
      $steps[] = [
        'up_to' => $float($row['up_to'] ?? NULL),
        'method' => (string) ($row['method'] ?? 'up'),
        'multiple' => (float) $row['multiple'],
        'subtract' => $float($row['subtract'] ?? NULL) ?? 0.0,
        'max' => $float($row['max'] ?? NULL),
      ];
    }
    // By limit, the step without one last.
    usort($steps, fn(array $a, array $b) => [$a['up_to'] === NULL, $a['up_to']] <=> [$b['up_to'] === NULL, $b['up_to']]);
    return $steps;
  }

  /**
   * Turns checkboxes into the list of checked values.
   */
  public static function checkedToConfig(mixed $value): array {
    return is_array($value) ? array_values(array_filter($value)) : [];
  }

  /**
   * Turns an empty choice into NULL.
   */
  public static function emptyToNull(mixed $value): ?string {
    return $value === '' || $value === NULL ? NULL : (string) $value;
  }

  /**
   * Splits a list of extensions, written with or without a dot.
   *
   * @return string[]
   *   Extensions in lowercase, IDNs as A-labels.
   */
  public static function extensions(string $list): array {
    $extensions = [];
    foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $extension) {
      $extensions[] = DomainName::normalize(ltrim($extension, '.'));
    }
    return array_values(array_unique(array_filter($extensions)));
  }

  /**
   * Returns an extension as buyers read it.
   */
  protected static function unicode(string $extension): string {
    if (!str_contains($extension, 'xn--') || !function_exists('idn_to_utf8')) {
      return $extension;
    }
    return idn_to_utf8($extension, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46) ?: $extension;
  }

  /**
   * Returns the enabled currencies.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Currency names keyed by code.
   */
  protected function currencies(): array {
    $currencies = [];
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
    foreach ($this->entityTypeManager->getStorage('commerce_currency')->loadByProperties(['status' => TRUE]) as $currency) {
      $currencies[$currency->getCurrencyCode()] = $this->t('@name (@code)', [
        '@name' => $currency->getName(),
        '@code' => $currency->getCurrencyCode(),
      ]);
    }
    ksort($currencies);
    return $currencies;
  }

  /**
   * Returns the price lists for product variations, keyed by UUID.
   *
   * @return array<string, string>
   *   Price list names, marked when disabled.
   */
  protected function priceLists(): array {
    $options = [];
    $lists = $this->entityTypeManager->getStorage('commerce_pricelist')->loadByProperties(['type' => 'commerce_product_variation']);
    /** @var \Drupal\commerce_pricelist\Entity\PriceListInterface $list */
    foreach ($lists as $list) {
      $name = (string) $list->getName();
      $options[(string) $list->uuid()] = $list->isEnabled() ? $name : (string) $this->t('@name (disabled)', ['@name' => $name]);
    }
    asort($options);
    return $options;
  }

}

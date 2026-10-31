<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\DnsHistory;
use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\DnsRecordsInterface;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A domain's DNS records at its registrar: edit, review, then apply.
 *
 * Applying replaces the zone at the registrar, so the owner sees what
 * changes first; the zone there before is kept and can be put back. The
 * zone is read again just before writing, and a change made there in the
 * meantime stops the write rather than being overwritten.
 */
final class DomainDnsForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerInterface $logger,
    protected DnsHistory $history,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('logger.channel.commerce_domains'),
      $container->get(DnsHistory::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_domain_dns';
  }

  /**
   * Returns the domain's registrar, when its API edits DNS records.
   */
  public static function provider(ProductInterface $product): ?ProviderInterface {
    if (!$product->hasField('domain') || !$product->hasField('domain_registrar') || $product->get('domain')->isEmpty()) {
      return NULL;
    }
    $provider = $product->get('domain_registrar')->entity;
    return $provider instanceof ProviderInterface && $provider->hasCapability(DnsRecordsInterface::class) ? $provider : NULL;
  }

  /**
   * Allows the tab for unsold domains at a registrar whose API edits DNS.
   */
  public static function access(ProductInterface $commerce_product, AccountInterface $account): AccessResultInterface {
    // A sold domain is the buyer's to manage now.
    $provider = Availability::isSold($commerce_product) ? NULL : self::provider($commerce_product);
    $result = AccessResult::allowedIf($provider !== NULL)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'manage domains at registrars'))
      ->addCacheableDependency($commerce_product);
    return $provider ? $result->addCacheableDependency($provider) : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProductInterface $commerce_product = NULL): array {
    $provider = self::provider($commerce_product);
    $name = (string) $commerce_product->get('domain')->value;
    $form_state->set('product', $commerce_product->id());
    $plugin = $provider->getPlugin();
    assert($plugin instanceof DnsRecordsInterface);
    $args = ['@label' => $provider->label(), '@domain' => $name];
    if ($error = $plugin->getSetupError()) {
      $this->messenger()->addWarning($error);
      return $form;
    }
    if (!$provider->status()) {
      $this->messenger()->addWarning($this->t('@label is disabled; enable it to edit DNS records there.', $args));
      return $form;
    }

    // Read once, and kept while the form is in use.
    if (!$form_state->has('current')) {
      try {
        $current = $plugin->getDnsRecords($name);
      }
      catch (ProviderException $e) {
        $this->messenger()->addError($this->t('@label could not be read: @error', $args + ['@error' => $e->getMessage()]));
        return $form;
      }
      $form_state->set('current', $current === NULL ? NULL : array_map(static fn(DnsRecord $record) => $record->toArray(), $current));
      $form_state->set('rows', $form_state->get('current') ?? []);
    }
    if ($form_state->get('current') === NULL) {
      $form['elsewhere'] = [
        '#type' => 'item',
        '#markup' => $this->t("@domain does not use the DNS of @label: its nameservers are elsewhere, so its records are there. Point it at the @label nameservers on the Registrar tab to edit its records here.", $args),
      ];
      return $form;
    }

    return $form_state->get('step') === 'review'
      ? $this->buildReview($form, $form_state, $plugin, $args)
      : $this->buildEdit($form, $form_state, $plugin, $name);
  }

  /**
   * Builds the editing step: the records as a table.
   *
   * @phpstan-param array<mixed> $form
   * @phpstan-return array<mixed>
   */
  protected function buildEdit(array $form, FormStateInterface $form_state, DnsRecordsInterface $plugin, string $name): array {
    $types = $plugin->dnsRecordTypes();
    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('Hosts are relative to @domain: @ for the domain itself, www, mail, _dmarc. Leave TTL empty for the default. Nothing changes at the registrar until you review and apply.', ['@domain' => $name]),
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
      '#empty' => $this->t('No records.'),
      '#tree' => TRUE,
    ];
    foreach ($form_state->get('rows') as $i => $row) {
      $options = $types;
      // A type the registrar has but does not offer for new records.
      if ($row['type'] !== '' && !isset($options[$row['type']])) {
        $options[$row['type']] = $row['type'];
      }
      $form['records'][$i] = [
        'type' => [
          '#type' => 'select',
          '#title' => $this->t('Type'),
          '#title_display' => 'invisible',
          '#options' => $options,
          '#default_value' => $row['type'] ?: 'A',
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
          '#default_value' => !empty($row['remove']),
        ],
        // What the registrar keeps with the record, written back as read.
        'extra' => ['#type' => 'value', '#value' => $row['extra'] ?? []],
        'id' => ['#type' => 'value', '#value' => $row['id'] ?? NULL],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add a record'),
      '#submit' => ['::addRecord'],
      '#limit_validation_errors' => [['records']],
    ];
    $form['actions']['review'] = [
      '#type' => 'submit',
      '#value' => $this->t('Review changes'),
      '#button_type' => 'primary',
      '#validate' => ['::validateRecords'],
      '#submit' => ['::review'],
    ];

    $kept = $this->history->list($name);
    if ($kept) {
      $form['history'] = [
        '#type' => 'details',
        '#title' => $this->t('Earlier zones'),
        '#description' => $this->t('The records as they were before each change made here, the latest first.'),
        'zones' => [
          '#type' => 'table',
          '#header' => [$this->t('Before the change of'), $this->t('Records'), ''],
        ],
      ];
      foreach ($kept as $index => $zone) {
        $form['history']['zones'][$index] = [
          'time' => ['#markup' => $this->dateFormatter->format($zone['time'], 'short')],
          'count' => ['#markup' => (string) count($zone['records'])],
          'restore' => [
            '#type' => 'submit',
            '#value' => $this->t('Review restoring this'),
            '#name' => 'restore_' . $index,
            '#submit' => ['::restore'],
            '#limit_validation_errors' => [],
            '#zone' => $index,
          ],
        ];
      }
    }
    return $form;
  }

  /**
   * Builds the review step: what changes at the registrar.
   *
   * @phpstan-param array<mixed> $form
   * @phpstan-return array<mixed>
   */
  protected function buildReview(array $form, FormStateInterface $form_state, DnsRecordsInterface $plugin, array $args): array {
    $diff = DnsRecord::diff($this->records($form_state->get('current')), $this->records($form_state->get('wanted')));
    $rows = [];
    $line = fn(DnsRecord $record) => [
      $record->type,
      $record->host,
      $record->value,
      $record->priority ?? '',
      $record->ttl ?? '',
    ];
    foreach ($diff['delete'] as $record) {
      $rows[] = ['class' => ['color-error'], 'data' => array_merge([$this->t('Remove')], $line($record))];
    }
    foreach ($diff['create'] as $record) {
      $rows[] = ['class' => ['color-success'], 'data' => array_merge([$this->t('Add')], $line($record))];
    }
    foreach ($diff['ttl'] as $record) {
      $rows[] = array_merge([$this->t('New TTL')], $line($record));
    }
    $form['changes'] = [
      '#type' => 'table',
      '#caption' => $this->t('Changes to the DNS of @domain at @label', $args),
      '#header' => [
        $this->t('Change'),
        $this->t('Type'),
        $this->t('Host'),
        $this->t('Value'),
        $this->t('Priority'),
        $this->t('TTL'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing changes.'),
    ];
    $form['note'] = [
      '#type' => 'item',
      '#markup' => $this->t('@unchanged records stay as they are. The zone as it is now is kept, so it can be put back.', ['@unchanged' => count($diff['keep']) - count($diff['ttl'])]),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply at @label', $args),
      '#button_type' => 'primary',
      '#submit' => ['::apply'],
      '#access' => (bool) $rows,
    ];
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to editing'),
      '#submit' => ['::back'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * Adds an empty row.
   */
  public function addRecord(array &$form, FormStateInterface $form_state): void {
    $rows = $this->enteredRows($form_state);
    $rows[] = (new DnsRecord('A', '@', ''))->toArray();
    $form_state->set('rows', $rows)->setRebuild();
  }

  /**
   * Checks every record that stays.
   */
  public function validateRecords(array &$form, FormStateInterface $form_state): void {
    // Records as the registrar holds them are not checked: they are there
    // already, some in the registrar's own format.
    $unchanged = array_map(static fn(DnsRecord $record) => $record->key(), $this->records($form_state->get('current')));
    foreach ($this->enteredRows($form_state) as $i => $row) {
      if (!empty($row['remove']) || in_array(DnsRecord::fromArray($row)->key(), $unchanged, TRUE)) {
        continue;
      }
      // An added row left empty is no record.
      if ($row['value'] === '' && $row['id'] === NULL && !isset($form_state->get('current')[$i])) {
        continue;
      }
      foreach (DnsRecord::fromArray($row)->problems() as $problem) {
        $form_state->setErrorByName("records][$i][value", $problem);
      }
    }
  }

  /**
   * Moves to the review step with the entered records.
   */
  public function review(array &$form, FormStateInterface $form_state): void {
    $rows = $this->enteredRows($form_state);
    $wanted = [];
    foreach ($rows as $i => $row) {
      if (empty($row['remove']) && !($row['value'] === '' && !isset($form_state->get('current')[$i]))) {
        $wanted[] = DnsRecord::fromArray($row)->toArray();
      }
    }
    $form_state->set('rows', $rows)->set('wanted', $wanted)->set('step', 'review')->setRebuild();
  }

  /**
   * Moves to the review step with a kept zone.
   */
  public function restore(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($form_state->get('product'));
    $records = $this->history->records((string) $product->get('domain')->value, (int) $form_state->getTriggeringElement()['#zone']);
    $wanted = array_map(static fn(DnsRecord $record) => $record->toArray(), $records);
    $form_state->set('rows', $wanted)->set('wanted', $wanted)->set('step', 'review')->setRebuild();
  }

  /**
   * Goes back to editing.
   */
  public function back(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', 'edit')->setRebuild();
  }

  /**
   * Writes the reviewed records at the registrar.
   */
  public function apply(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($form_state->get('product'));
    $provider = self::provider($product);
    $name = (string) $product->get('domain')->value;
    $plugin = $provider->getPlugin();
    assert($plugin instanceof DnsRecordsInterface);
    $context = ['@domain' => $name, '@provider' => $provider->label()];
    $wanted = $this->records($form_state->get('wanted'));
    try {
      // Read again: a change made at the registrar since the page was opened
      // is not overwritten unseen.
      $now = $plugin->getDnsRecords($name);
      $before = $this->records($form_state->get('current'));
      $changed = DnsRecord::diff($before, $now ?? []);
      if ($now === NULL || $changed['delete'] || $changed['create']) {
        $this->messenger()->addError($this->t('The DNS of @domain changed at @provider since this page was opened. Review it again before changing it.', $context));
        $form_state->set('current', NULL === $now ? NULL : array_map(static fn(DnsRecord $record) => $record->toArray(), $now));
        $form_state->set('rows', $form_state->get('current') ?? [])->set('step', 'edit')->setRebuild();
        return;
      }
      $this->history->keep($name, (string) $provider->id(), $now);
      $plugin->setDnsRecords($name, $wanted);
    }
    catch (ProviderException $e) {
      $this->messenger()->addError($this->t('@provider refused: @error', $context + ['@error' => $e->getMessage()]));
      $this->logger->warning('@domain at @provider: DNS records not changed: @error', $context + ['@error' => $e->getMessage()]);
      $form_state->setRebuild();
      return;
    }
    $diff = DnsRecord::diff($now, $wanted);
    $context += ['@added' => count($diff['create']), '@removed' => count($diff['delete'])];
    $this->logger->notice('@domain at @provider: DNS records changed, @added added and @removed removed.', $context);
    $this->messenger()->addStatus($this->t('The DNS of @domain at @provider is changed: @added records added, @removed removed. Changes reach resolvers as the old records expire.', $context));
    // Start again from what the registrar holds now.
    $form_state->setStorage(['product' => $form_state->get('product')]);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Returns the rows as entered.
   *
   * @return array<int, array<string, mixed>>
   *   Rows keyed as the table has them.
   */
  protected function enteredRows(FormStateInterface $form_state): array {
    $rows = [];
    foreach ((array) $form_state->getValue('records', []) as $i => $row) {
      $record = DnsRecord::fromArray($row);
      $rows[(int) $i] = $record->toArray() + ['remove' => !empty($row['remove'])];
      $rows[(int) $i]['value'] = $record->value;
    }
    return $rows ?: (array) $form_state->get('rows');
  }

  /**
   * Turns stored values into records.
   *
   * @param array<int, array<string, mixed>>|null $values
   *   Records as arrays.
   *
   * @return \Drupal\commerce_domains\DnsRecord[]
   *   The records.
   */
  protected function records(?array $values): array {
    return array_map(DnsRecord::fromArray(...), $values ?? []);
  }

}

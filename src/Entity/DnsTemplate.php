<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Entity;

use Drupal\commerce_domains\DnsRecord;
use Drupal\commerce_domains\DnsTemplateListBuilder;
use Drupal\commerce_domains\Form\DnsTemplateForm;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the DNS template entity type.
 */
#[ConfigEntityType(
  id: 'commerce_domains_dns_template',
  label: new TranslatableMarkup('DNS template'),
  label_collection: new TranslatableMarkup('DNS templates'),
  label_singular: new TranslatableMarkup('DNS template'),
  label_plural: new TranslatableMarkup('DNS templates'),
  config_prefix: 'dns_template',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => DnsTemplateListBuilder::class,
    'form' => [
      'add' => DnsTemplateForm::class,
      'edit' => DnsTemplateForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/commerce/domains/settings/dns-templates',
    'add-form' => '/admin/commerce/domains/settings/dns-templates/add',
    'edit-form' => '/admin/commerce/domains/settings/dns-templates/{commerce_domains_dns_template}',
    'delete-form' => '/admin/commerce/domains/settings/dns-templates/{commerce_domains_dns_template}/delete',
  ],
  admin_permission: 'administer commerce_domains providers',
  label_count: [
    'singular' => '@count DNS template',
    'plural' => '@count DNS templates',
  ],
  config_export: [
    'id',
    'label',
    'records',
  ],
)]
class DnsTemplate extends ConfigEntityBase implements DnsTemplateInterface {

  /**
   * The template ID.
   *
   * @var string|null
   */
  protected ?string $id = NULL;

  /**
   * The template's name.
   *
   * @var string|null
   */
  protected ?string $label = NULL;

  /**
   * The records.
   *
   * @var array<int, array{type: string, host: string, value: string, priority: int|null, ttl: int|null, note: string|null}>
   */
  protected array $records = [];

  /**
   * {@inheritdoc}
   */
  public function getRecords(): array {
    return $this->records;
  }

  /**
   * {@inheritdoc}
   */
  public function setRecords(array $records): static {
    if (count($records) > self::MAX_RECORDS) {
      throw new \InvalidArgumentException(sprintf('A DNS template holds up to %d records.', self::MAX_RECORDS));
    }
    $this->records = [];
    foreach (array_values($records) as $values) {
      $record = DnsRecord::fromArray($values);
      $this->records[] = [
        'type' => $record->type,
        'host' => $record->host,
        'value' => $record->value,
        'priority' => $record->priority,
        'ttl' => $record->ttl,
        'note' => self::note($values['note'] ?? NULL),
      ];
    }
    return $this;
  }

  /**
   * Returns a record's note, trimmed; NULL when it has none.
   */
  protected static function note(mixed $note): ?string {
    $note = is_scalar($note) ? trim((string) $note) : '';
    return $note === '' ? NULL : mb_substr($note, 0, self::NOTE_LENGTH);
  }

  /**
   * {@inheritdoc}
   */
  public function recordsFor(string $name): array {
    return array_map(
      static fn(array $values) => DnsRecord::fromArray(['value' => str_replace(self::PLACEHOLDER, $name, (string) $values['value'])] + $values),
      $this->records,
    );
  }

}

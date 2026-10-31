<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_handover\Entity;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\HandoverAccessControlHandler;
use Drupal\commerce_domains_handover\HandoverListBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\views\EntityViewsData;

/**
 * Handing one sold domain over to its buyer.
 */
#[ContentEntityType(
  id: 'commerce_domains_handover',
  label: new TranslatableMarkup('Domain handover'),
  label_collection: new TranslatableMarkup('Domain handovers'),
  label_singular: new TranslatableMarkup('domain handover'),
  label_plural: new TranslatableMarkup('domain handovers'),
  entity_keys: [
    'id' => 'handover_id',
    'uuid' => 'uuid',
    'label' => 'domain',
  ],
  handlers: [
    'list_builder' => HandoverListBuilder::class,
    'access' => HandoverAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
  ],
  links: [
    'collection' => '/admin/commerce/domains/handovers',
  ],
  admin_permission: 'hand over domains',
  base_table: 'commerce_domains_handover',
  label_count: [
    'singular' => '@count domain handover',
    'plural' => '@count domain handovers',
  ],
)]
class Handover extends ContentEntityBase implements HandoverInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getOrder(): ?OrderInterface {
    $order = $this->get('order_id')->entity;
    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrderId(): ?int {
    $id = (int) $this->get('order_id')->target_id;
    return $id ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrderItem(): ?OrderItemInterface {
    $item = $this->get('order_item_id')->entity;
    return $item instanceof OrderItemInterface ? $item : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getProduct(): ?ProductInterface {
    $product = $this->get('product_id')->entity;
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDomain(): string {
    return (string) $this->get('domain')->value;
  }

  /**
   * {@inheritdoc}
   *
   * While the handover is open the domain is still the seller's, wherever the
   * catalog records it now: registrar syncs and edits move it. Once the
   * handover is closed, the registrar kept at its last step.
   */
  public function getRegistrar(): ?ProviderInterface {
    $provider = $this->isOpen() ? $this->productRegistrar() : NULL;
    $provider ??= $this->get('registrar')->entity;
    return $provider instanceof ProviderInterface ? $provider : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTransfer(): ?Transfer {
    $order = $this->getOrder();
    return $order ? Transfer::fromOrder($order) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getState(): StateItemInterface {
    /** @var \Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface $state */
    $state = $this->get('state')->first();
    return $state;
  }

  /**
   * {@inheritdoc}
   */
  public function isOpen(): bool {
    return !in_array($this->getState()->getId(), self::CLOSED, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getTransferredTime(): ?int {
    $time = $this->get('transferred')->value;
    return $time === NULL ? NULL : (int) $time;
  }

  /**
   * {@inheritdoc}
   */
  public function setTransferredTime(int $time): static {
    $this->set('transferred', $time);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    // Keep where the domain is at each step, up to the one that closes the
    // handover: marking it transferred takes the registrar off the product.
    $was_open = $this->isNew() || !in_array($this->getState()->getOriginalId(), self::CLOSED, TRUE);
    if ($was_open && ($provider = $this->productRegistrar())) {
      $this->set('registrar', $provider->id());
    }
    // A transfer marked without a date happened when it was marked.
    if ($this->getState()->getId() === 'transferred' && $this->getTransferredTime() === NULL) {
      $this->setTransferredTime(\Drupal::time()->getRequestTime());
    }
  }

  /**
   * Returns the registrar the catalog records the domain at, if any.
   */
  protected function productRegistrar(): ?ProviderInterface {
    $product = $this->getProduct();
    $provider = $product && $product->hasField('domain_registrar') ? $product->get('domain_registrar')->entity : NULL;
    return $provider instanceof ProviderInterface ? $provider : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setSetting('target_type', 'commerce_order')
      ->setRequired(TRUE);

    $fields['order_item_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order item'))
      ->setDescription(new TranslatableMarkup('The order item of the domain; each has one handover at most.'))
      ->setSetting('target_type', 'commerce_order_item')
      ->setRequired(TRUE);

    $fields['product_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Domain product'))
      ->setSetting('target_type', 'commerce_product');

    $fields['domain'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setDescription(new TranslatableMarkup('The domain in lowercase, as it was sold.'))
      ->setSetting('max_length', 253)
      ->setRequired(TRUE);

    // Kept, because the product stops naming it once the domain has left.
    $fields['registrar'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Registrar'))
      ->setDescription(new TranslatableMarkup('Where the domain was registered at the last step of the handover.'))
      ->setSetting('target_type', 'commerce_domains_provider');

    $fields['state'] = BaseFieldDefinition::create('state')
      ->setLabel(new TranslatableMarkup('State'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setSetting('workflow', 'commerce_domains_handover_default');

    $fields['transferred'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Transferred'))
      ->setDescription(new TranslatableMarkup('When the domain reached the buyer.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Started'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Entity;

use Drupal\commerce_domains_offer\OfferListBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * An offer a buyer made on a domain.
 */
#[ContentEntityType(
  id: 'commerce_domains_offer',
  label: new TranslatableMarkup('Domain offer'),
  label_collection: new TranslatableMarkup('Domain offers'),
  label_singular: new TranslatableMarkup('domain offer'),
  label_plural: new TranslatableMarkup('domain offers'),
  entity_keys: [
    'id' => 'offer_id',
    'uuid' => 'uuid',
    'label' => 'domain',
    'owner' => 'uid',
  ],
  handlers: [
    'list_builder' => OfferListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'delete' => ContentEntityDeleteForm::class,
    ],
  ],
  links: [
    'canonical' => '/admin/commerce/domain-offers/{commerce_domains_offer}',
    'collection' => '/admin/commerce/domain-offers',
    'delete-form' => '/admin/commerce/domain-offers/{commerce_domains_offer}/delete',
  ],
  admin_permission: 'administer domain offers',
  base_table: 'commerce_domains_offer',
  label_count: [
    'singular' => '@count domain offer',
    'plural' => '@count domain offers',
  ],
)]
class Offer extends ContentEntityBase implements OfferInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getVariation(): ?ProductVariationInterface {
    $variation = $this->get('variation')->entity;
    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDomain(): string {
    return (string) $this->get('domain')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStore(): ?StoreInterface {
    $store = $this->get('store')->entity;
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAmount(): ?Price {
    return $this->price('amount');
  }

  /**
   * {@inheritdoc}
   */
  public function getCounterAmount(): ?Price {
    return $this->price('counter_amount');
  }

  /**
   * {@inheritdoc}
   */
  public function setCounterAmount(Price $amount): static {
    $this->set('counter_amount', $amount);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAgreedAmount(): ?Price {
    return $this->getCounterAmount() ?? $this->getAmount();
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail(): string {
    return (string) $this->get('mail')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getBuyerName(): string {
    return (string) $this->get('name')->value;
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
  public function getOrder(): ?OrderInterface {
    $order = $this->get('order')->entity;
    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setOrder(OrderInterface $order): static {
    $this->set('order', $order);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getToken(): string {
    return (string) $this->get('token')->value;
  }

  /**
   * Returns a price field's value.
   */
  protected function price(string $field): ?Price {
    if ($this->get($field)->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
    $item = $this->get($field)->first();
    return $item->toPrice();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->getToken() === '') {
      $this->set('token', Crypt::randomBytesBase64(32));
    }
    // The name as the buyer saw it, kept if the product is renamed.
    $product = $this->getVariation()?->getProduct();
    if ($this->getDomain() === '' && $product) {
      $this->set('domain', $product->hasField('domain') && !$product->get('domain')->isEmpty() ? $product->get('domain')->value : $product->label());
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    /** @var \Drupal\Core\Field\BaseFieldDefinition $owner */
    $owner = $fields['uid'];
    $owner
      ->setLabel(new TranslatableMarkup('Buyer'))
      ->setDescription(new TranslatableMarkup("The buyer's account; anonymous for buyers without one."));

    $fields['variation'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Variation'))
      ->setSetting('target_type', 'commerce_product_variation')
      ->setRequired(TRUE);

    $fields['domain'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setSetting('max_length', 253);

    $fields['store'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Store'))
      ->setSetting('target_type', 'commerce_store');

    $fields['amount'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Offer'))
      ->setRequired(TRUE);

    $fields['counter_amount'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Counter-offer'));

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setSetting('max_length', 255);

    $fields['mail'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Email'))
      ->setRequired(TRUE);

    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Phone'))
      ->setSetting('max_length', 64);

    $fields['message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Message'));

    $fields['state'] = BaseFieldDefinition::create('state')
      ->setLabel(new TranslatableMarkup('State'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setSetting('workflow', 'commerce_domains_offer_default');

    $fields['order'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setDescription(new TranslatableMarkup('The order accepting the offer created.'))
      ->setSetting('target_type', 'commerce_order');

    $fields['token'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Token'))
      ->setSetting('max_length', 64);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Made'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}

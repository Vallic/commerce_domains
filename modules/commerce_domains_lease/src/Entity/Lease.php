<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_lease\Entity;

use Drupal\commerce_domains_lease\LeaseListBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * A lease to own a domain.
 */
#[ContentEntityType(
  id: 'commerce_domains_lease',
  label: new TranslatableMarkup('Domain lease'),
  label_collection: new TranslatableMarkup('Domain leases'),
  label_singular: new TranslatableMarkup('domain lease'),
  label_plural: new TranslatableMarkup('domain leases'),
  entity_keys: [
    'id' => 'lease_id',
    'uuid' => 'uuid',
    'label' => 'domain',
    'owner' => 'uid',
  ],
  handlers: [
    'list_builder' => LeaseListBuilder::class,
    'views_data' => EntityViewsData::class,
  ],
  links: [
    'canonical' => '/admin/commerce/domains/leases/{commerce_domains_lease}',
    'collection' => '/admin/commerce/domains/leases',
  ],
  admin_permission: 'administer domain leases',
  base_table: 'commerce_domains_lease',
  label_count: [
    'singular' => '@count domain lease',
    'plural' => '@count domain leases',
  ],
)]
class Lease extends ContentEntityBase implements LeaseInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

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
  public function getDomain(): string {
    return (string) $this->get('domain')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentMethod(): ?PaymentMethodInterface {
    $method = $this->get('payment_method')->entity;
    return $method instanceof PaymentMethodInterface ? $method : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentGateway(): ?PaymentGatewayInterface {
    $gateway = $this->get('payment_gateway')->entity ?? $this->getPaymentMethod()?->getPaymentGateway();
    return $gateway instanceof PaymentGatewayInterface ? $gateway : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isManual(): bool {
    // Without a card to charge, the buyer pays each payment.
    return !$this->getPaymentMethod()?->isReusable();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequested(): ?int {
    $value = $this->get('requested')->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getMonths(): int {
    return (int) $this->get('months')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTotal(): Price {
    return $this->price('total');
  }

  /**
   * {@inheritdoc}
   */
  public function getDownPayment(): Price {
    return $this->price('down_payment');
  }

  /**
   * {@inheritdoc}
   */
  public function getInstallment(): Price {
    return $this->price('installment');
  }

  /**
   * {@inheritdoc}
   */
  public function getPaid(): Price {
    return $this->get('paid')->isEmpty() ? new Price('0', $this->getTotal()->getCurrencyCode()) : $this->price('paid');
  }

  /**
   * {@inheritdoc}
   */
  public function getRemaining(): Price {
    $remaining = $this->getTotal()->subtract($this->getPaid());
    return $remaining->isNegative() ? new Price('0', $remaining->getCurrencyCode()) : $remaining;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentsMade(): int {
    return (int) $this->get('payments_made')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getNextAmount(): Price {
    // A down payment by bank transfer may not have arrived yet: it comes
    // first.
    $down = $this->getDownPayment()->subtract($this->getPaid());
    if ($down->isPositive()) {
      return $down;
    }
    $remaining = $this->getRemaining();
    // The last payment makes up what rounding left over.
    if ($this->getPaymentsMade() + 1 >= $this->getMonths() || $remaining->lessThan($this->getInstallment())) {
      return $remaining;
    }
    return $this->getInstallment();
  }

  /**
   * {@inheritdoc}
   */
  public function getNextDue(): ?int {
    $value = $this->get('next_due')->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getFailures(): int {
    return (int) $this->get('failures')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getState(): string {
    return (string) $this->get('state')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isOpen(): bool {
    return in_array($this->getState(), [self::ACTIVE, self::OVERDUE], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function stateLabels(): array {
    return [
      self::ACTIVE => new TranslatableMarkup('Active'),
      self::OVERDUE => new TranslatableMarkup('Payment overdue'),
      self::DEFAULTED => new TranslatableMarkup('In default'),
      self::COMPLETED => new TranslatableMarkup('Paid in full'),
      self::CANCELED => new TranslatableMarkup('Canceled'),
    ];
  }

  /**
   * Returns a price field's value.
   */
  protected function price(string $field): Price {
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
    $item = $this->get($field)->first();
    return $item->toPrice();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    /** @var \Drupal\Core\Field\BaseFieldDefinition $owner */
    $owner = $fields['uid'];
    $owner->setLabel(new TranslatableMarkup('Buyer'));

    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setSetting('target_type', 'commerce_order')
      ->setRequired(TRUE);

    $fields['domain'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setSetting('max_length', 253);

    $fields['payment_method'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Card'))
      ->setDescription(new TranslatableMarkup('The saved card the monthly payments are charged to.'))
      ->setSetting('target_type', 'commerce_payment_method');

    $fields['payment_gateway'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Paid through'))
      ->setDescription(new TranslatableMarkup('The payment gateway the buyer chose at checkout.'))
      ->setSetting('target_type', 'commerce_payment_gateway');

    $fields['months'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Monthly payments'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE);

    $fields['total'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Total'))
      ->setRequired(TRUE);

    $fields['down_payment'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Down payment'))
      ->setRequired(TRUE);

    $fields['installment'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Monthly payment'))
      ->setRequired(TRUE);

    $fields['paid'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Paid'))
      ->setDescription(new TranslatableMarkup('What was paid so far, the down payment included.'));

    $fields['payments_made'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Monthly payments made'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['next_due'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Next payment'))
      ->setDescription(new TranslatableMarkup('When the next payment is due, or retried; empty once none is.'));

    $fields['requested'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Payment requested'))
      ->setDescription(new TranslatableMarkup('When the buyer was asked for the payment due, on a lease without a saved card; empty until then.'));

    $fields['failures'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Failed attempts'))
      ->setDescription(new TranslatableMarkup('Failed attempts at the payment due.'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['last_error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Last failure'));

    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setSetting('allowed_values_function', [static::class, 'stateLabels'])
      ->setDefaultValue(self::ACTIVE)
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Started'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}

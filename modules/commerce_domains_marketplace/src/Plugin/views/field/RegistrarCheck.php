<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_marketplace\Plugin\views\field;

use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows when the domain's registrar account was last read whole.
 *
 * Flags domains recorded at the registrar that the account didn't hold.
 */
#[ViewsField('commerce_domains_registrar_check')]
final class RegistrarCheck extends FieldPluginBase {

  /**
   * The registrar sync.
   *
   * @var \Drupal\commerce_domains\RegistrarSync
   */
  protected RegistrarSync $registrarSync;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->registrarSync = $container->get(RegistrarSync::class);
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Read from the product entity and the registrar's last check.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $product = $this->getEntity($values);
    if (!$product instanceof ProductInterface || !$product->hasField('domain_registrar')) {
      return '';
    }
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface|null $provider */
    $provider = $product->get('domain_registrar')->entity;
    if (!$provider || !$provider->hasCapability(RegistrarInterface::class)) {
      return '';
    }
    $check = $this->registrarSync->lastCheck((string) $provider->id());
    if ($check === NULL) {
      return $this->t('Never read');
    }
    $date = $this->dateFormatter->format($check['time'], 'short');
    if (in_array((string) $product->get('domain')->value, $check['missing'], TRUE)) {
      return $this->t('Not in the account on @date', ['@date' => $date]);
    }
    return $this->t('Read @date', ['@date' => $date]);
  }

}

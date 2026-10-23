<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\EventSubscriber;

use Drupal\commerce_domains\DomainName;
use Drupal\commerce_domains\DomainNameParser;
use Drupal\commerce_product\Event\ProductEvent;
use Drupal\commerce_product\Event\ProductEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Derives a domain product's name fields from its title.
 */
class ProductSubscriber implements EventSubscriberInterface {

  public function __construct(protected DomainNameParser $parser) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ProductEvents::PRODUCT_PRESAVE => 'onPresave'];
  }

  /**
   * Fills domain, domain_label and domain_extension from the title.
   */
  public function onPresave(ProductEvent $event): void {
    $product = $event->getProduct();
    if (!$product->hasField('domain')) {
      return;
    }
    $parsed = $this->parser->parse($product->getTitle());
    $product->set('domain', $parsed ? $parsed->name : DomainName::normalize($product->getTitle()));
    $product->set('domain_label', $parsed?->label);
    $product->set('domain_extension', $parsed?->extension);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_offer\Plugin\Block;

use Drupal\commerce_domains_offer\Form\OfferForm;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The offer form for the domain on the page.
 *
 * Empty when the domain doesn't take offers.
 */
#[Block(
  id: 'commerce_domains_offer_form',
  admin_label: new TranslatableMarkup('Domain offer form'),
  category: new TranslatableMarkup('Commerce'),
  context_definitions: [
    'commerce_product' => new EntityContextDefinition('entity:commerce_product', new TranslatableMarkup('Product')),
  ],
)]
final class OfferFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->formBuilder = $container->get('form_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'make domain offers');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $product = $this->getContextValue('commerce_product');
    $variations = $product instanceof ProductInterface ? $product->getVariations() : [];
    if (!$variations) {
      return [];
    }
    $build = $this->formBuilder->getForm(OfferForm::class, reset($variations));
    // Whether offers are taken follows the domain and its variation.
    $metadata = CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($product);
    foreach ($variations as $variation) {
      $metadata->addCacheableDependency($variation);
    }
    $metadata->applyTo($build);
    return $build;
  }

}

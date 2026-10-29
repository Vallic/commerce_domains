<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_bulk\Form;

use Drupal\commerce_domains_bulk\BulkEditor;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets the prices of the selected domains from the pricing rules.
 */
final class PriceRulesForm extends BulkFormBase {

  /**
   * The pricing rules.
   */
  protected PriceRules $priceRules;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->priceRules = $container->get(PriceRules::class);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actionId(): string {
    return 'commerce_domains_apply_price_rules';
  }

  /**
   * {@inheritdoc}
   */
  protected function edit(): string {
    return BulkEditor::PRICE_RULES;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->products), 'Apply the pricing rules to 1 domain?', 'Apply the pricing rules to @count domains?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $settings = Url::fromRoute('commerce_domains_pricing.settings');
    return $this->t('Each domain gets the price of the first <a href=":rules">pricing rule</a> that matches it, in place of its own. Domains no rule matches keep their price.', [
      ':rules' => $settings->toString(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Apply pricing rules');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    if (!is_array($form)) {
      return $form;
    }
    // What each domain would get, in place of the plain list.
    unset($form['domains']);
    $form['preview'] = [
      '#type' => 'table',
      '#header' => [$this->t('Domain'), $this->t('Price now'), $this->t('From the rules')],
      '#weight' => -10,
    ];
    foreach ($this->products as $id => $product) {
      $variations = $product->getVariations();
      $variation = reset($variations);
      $atom_premium = $product->hasField('atom_premium') && (bool) $product->get('atom_premium')->value;
      $price = $this->priceRules->price($product->getTitle(), $atom_premium);
      $form['preview'][$id] = [
        'domain' => ['#plain_text' => $product->label()],
        'now' => $variation && $variation->getPrice() ? [
          '#type' => 'inline_template',
          '#template' => '{{ price|commerce_price_format }}',
          '#context' => ['price' => $variation->getPrice()],
        ] : ['#markup' => $this->t('None')],
        'rule' => $price ? [
          '#type' => 'inline_template',
          '#template' => '{{ price|commerce_price_format }}',
          '#context' => ['price' => $price],
        ] : ['#markup' => $this->t('No rule matches: kept')],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function options(FormStateInterface $form_state): array {
    return [];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingPluginBase;
use Drupal\commerce_domains\SaleTerms;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets the general sale terms, and shows what each marketplace does.
 */
class SaleTermsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_sale_terms';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_domains.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t("Terms resolve from these general ones, to a marketplace's own on its provider form, to a domain's own on its variation. Each applies where the next leaves a term empty. Marketplaces get a change with the next full sync.") . '</p>',
    ];
    $percent = fn(\Stringable $title, string $key) => [
      '#type' => 'number',
      '#title' => $title,
      '#field_suffix' => '%',
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains.settings:' . $key,
    ];
    $form['floor_percent'] = $percent($this->t('Floor'), 'floor_percent');
    $form['minimum_offer_break'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum offer changes at this price'),
      '#min' => 1,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains.settings:minimum_offer_break',
    ];
    $form['minimum_offer_percent'] = $percent($this->t('Minimum offer below that price'), 'minimum_offer_percent');
    $form['minimum_offer_percent_above'] = $percent($this->t('Minimum offer from that price'), 'minimum_offer_percent_above');
    $form['automatic_lease'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer lease-to-own by price'),
      '#description' => $this->t('On this site, and on marketplaces without a setting of their own. When off, only domains with their own lease term can be leased.'),
      '#config_target' => 'commerce_domains.settings:automatic_lease',
    ];
    $form['lease'] = [
      '#type' => 'table',
      '#caption' => $this->t('Longest lease by price, the same on every marketplace'),
      '#header' => [$this->t('Price'), $this->t('Longest lease')],
      '#rows' => $this->leaseRows(),
    ];
    $form['marketplaces'] = [
      '#type' => 'table',
      '#caption' => $this->t('What your marketplaces do with empty terms'),
      '#header' => [
        $this->t('Marketplace'),
        $this->t('Floor'),
        $this->t('Minimum offer'),
        $this->t('Lease by price'),
        $this->t('Own terms'),
        $this->t('Also'),
      ],
      '#rows' => $this->marketplaceRows(),
      '#empty' => $this->t('No marketplaces yet.'),
    ];
    $form['currency'] = [
      '#markup' => '<p>' . $this->t("Amounts are compared with the price in the marketplace's currency, after its price factor where it has one, and with the order's total on this site.") . '</p>',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns the rows of the lease rule.
   */
  protected function leaseRows(): array {
    $rows = [];
    $from = NULL;
    foreach (SaleTerms::LEASE_TERMS as $below => $months) {
      $rows[] = [
        $from === NULL
          ? $this->t('Below @below', ['@below' => number_format($below)])
          : $this->t('@from to @below', ['@from' => number_format($from), '@below' => number_format($below)]),
        $months ? $this->formatPlural($months, '1 month', '@count months') : $this->t('No lease'),
      ];
      $from = $below;
    }
    $rows[] = [
      $this->t('@from and above', ['@from' => number_format((int) $from)]),
      $this->formatPlural(SaleTerms::LEASE_TERM_ABOVE, '1 month', '@count months'),
    ];
    return $rows;
  }

  /**
   * Returns a row for each enabled marketplace.
   */
  protected function marketplaceRows(): array {
    $rows = [];
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $provider) {
      $plugin = $provider instanceof ProviderInterface && $provider->status() ? $provider->getPlugin() : NULL;
      if (!$plugin instanceof ListingPluginBase) {
        continue;
      }
      $terms = $plugin->fallbackTerms();
      $rules = $plugin->saleTermRules();
      // The longest the price rule reaches here. A marketplace's own limit can
      // be longer, and then only a domain's own term gets there.
      $by_price = min($rules->leaseMonths(PHP_FLOAT_MAX), $terms['lease_months']);
      $also = array_filter([
        $terms['lease_months'] > $by_price ? $this->t("A domain's own term can go up to @months months.", ['@months' => $terms['lease_months']]) : NULL,
        $terms['note'] ?? NULL,
      ]);
      $rows[] = [
        ['data' => $provider->toLink($provider->label(), 'edit-form')->toRenderable()],
        $terms['floor'] ?? $this->t('None'),
        $terms['minimum_offer'] ?? $this->t('None'),
        match (TRUE) {
          !$terms['lease_months'] => $this->t('None'),
          $by_price < 2 => $this->t('Off'),
          default => $this->t('Up to @months months', ['@months' => $by_price]),
        },
        $this->ownTerms($provider),
        implode(' ', array_map('strval', $also)),
      ];
    }
    return $rows;
  }

  /**
   * Lists the terms a marketplace sets for itself.
   */
  protected function ownTerms(ProviderInterface $provider): string {
    $labels = [
      'floor_percent' => $this->t('floor'),
      'minimum_offer_percent' => $this->t('minimum offer'),
      'minimum_offer_percent_above' => $this->t('minimum offer'),
      'automatic_lease' => $this->t('lease'),
      'lease_months' => $this->t('lease'),
    ];
    return implode(', ', array_unique(array_map('strval', array_intersect_key($labels, $provider->getSaleTerms()))));
  }

}

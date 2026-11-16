<?php

declare(strict_types=1);

namespace Drupal\commerce_domains\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ListingPluginBase;
use Drupal\commerce_domains\SaleTermRules;
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
      '#header' => [$this->t('Below this price'), $this->t('Longest lease')],
      '#suffix' => '<div class="description">' . $this->t('Lowest price first. 0 months for no lease; one payment is no lease either. Marketplaces with a shorter limit of their own stop there. Empty rows are dropped.') . '</div>',
    ];
    $general = SaleTermRules::general();
    $bands = $general->leaseTerms;
    // Room for two more each time.
    for ($delta = 0; $delta < count($bands) + 2; $delta++) {
      $form['lease'][$delta]['below'] = [
        '#type' => 'number',
        '#title' => $this->t('Below this price'),
        '#title_display' => 'invisible',
        '#min' => 1,
        '#default_value' => $bands[$delta]['below'] ?? NULL,
      ];
      $form['lease'][$delta]['months'] = $this->monthsElement($bands[$delta]['months'] ?? NULL) + [
        '#title' => $this->t('Longest lease'),
        '#title_display' => 'invisible',
      ];
    }
    $form['lease_term_above'] = $this->monthsElement($general->leaseTermAbove) + [
      '#title' => $this->t('Longest lease from the last price up'),
      '#required' => TRUE,
    ];
    $form['lease_down_payment'] = $percent($this->t('Down payment on a lease'), 'lease_down_payment');
    $form['lease_down_payment']['#max'] = 90;
    $form['lease_down_payment']['#description'] = $this->t("The share of the price paid first, for domains that set none: at checkout on this site, and in the files of marketplaces that take one. The rest is paid in the lease's monthly payments.");
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
   * Returns a field for a number of monthly payments.
   */
  protected function monthsElement(?int $months): array {
    return [
      '#type' => 'number',
      '#min' => 0,
      '#max' => SaleTermRules::MAX_LEASE_MONTHS,
      '#field_suffix' => $this->t('months'),
      '#default_value' => $months,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $previous = 0;
    foreach ((array) $form_state->getValue('lease') as $delta => $band) {
      $below = $band['below'] ?? '';
      $months = $band['months'] ?? '';
      if ($below === '' && $months === '') {
        continue;
      }
      if ($below === '' || $months === '') {
        $form_state->setErrorByName("lease][$delta][" . ($below === '' ? 'below' : 'months'), $this->t('Give each price band both a price and a lease.'));
        continue;
      }
      if ((int) $below <= $previous) {
        $form_state->setErrorByName("lease][$delta][below", $this->t('Each price band starts above the one before it.'));
      }
      $previous = (int) $below;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $bands = [];
    foreach ((array) $form_state->getValue('lease') as $band) {
      if (($band['below'] ?? '') !== '' && ($band['months'] ?? '') !== '') {
        $bands[] = ['below' => (int) $band['below'], 'months' => (int) $band['months']];
      }
    }
    $this->config('commerce_domains.settings')
      ->set('lease_terms', $bands)
      ->set('lease_term_above', (int) $form_state->getValue('lease_term_above'))
      ->save();
    parent::submitForm($form, $form_state);
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

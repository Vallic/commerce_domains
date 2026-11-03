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
 * Turns automatic leases on or off, and shows the rules for empty sale terms.
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
      '#markup' => '<p>' . $this->t("A domain's own floor, minimum offer and lease-to-own, on its variation, always come first. The rules here apply to the ones left empty.") . '</p>',
    ];
    $form['automatic_lease'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer lease-to-own by price'),
      '#description' => $this->t('On this site and on marketplaces. When off, only domains with their own lease term can be leased. Marketplaces get the change with the next full sync.'),
      '#config_target' => 'commerce_domains.settings:automatic_lease',
    ];
    $form['lease'] = [
      '#type' => 'table',
      '#caption' => $this->t('Longest lease by price'),
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
        $this->t('Longest lease they take'),
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
      $rows[] = [
        $provider->label(),
        $terms['floor'] ?? $this->t('None'),
        $terms['minimum_offer'] ?? $this->t('None'),
        $terms['lease_months'] ? $this->formatPlural($terms['lease_months'], '1 month', '@count months') : $this->t('None'),
        $terms['note'] ?? '',
      ];
    }
    return $rows;
  }

}

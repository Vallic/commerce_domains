<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_domains\Catalog;
use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\MarketplaceInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Works out the next steps of setting up a portfolio site, from its state.
 *
 * Nothing is asked of providers' APIs: a marketplace counts as connected
 * when its keys are set, and parked domains count as pointed at the lander
 * once a visitor has come through it.
 */
class Checklist {

  // When a visitor last came through the lander; see LanderSubscriber.
  public const LANDER_KEY = 'commerce_domains_owner.lander_used';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected Catalog $catalog,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
  ) {}

  /**
   * Returns the steps, in order.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, done: bool, help: \Drupal\Core\StringTranslation\TranslatableMarkup, url: \Drupal\Core\Url|null}>
   *   The steps, keyed by ID: registrar, domains, marketplace, payment,
   *   lander and notify.
   */
  public function steps(): array {
    /** @var \Drupal\commerce_domains\Entity\ProviderInterface[] $providers */
    $providers = $this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple();
    $steps['registrar'] = [
      'title' => new TranslatableMarkup('Add a registrar'),
      // A provider without an API stands for a registrar too.
      'done' => (bool) array_filter($providers, fn(ProviderInterface $provider) => $provider->getPluginId() === NULL || $provider->hasCapability(RegistrarInterface::class)),
      'help' => new TranslatableMarkup('Record where your domains are registered. With the registrar API, expiry dates and auto-renew follow the account.'),
      'url' => Url::fromRoute('entity.commerce_domains_provider.add_form'),
    ];

    $types = $this->catalog->productTypes();
    $imports = $this->moduleHandler->moduleExists('commerce_domains_import');
    $steps['domains'] = [
      'title' => new TranslatableMarkup('Import your domains'),
      'done' => $types && $this->entityTypeManager->getStorage('commerce_product')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $types, 'IN')
        ->range(0, 1)
        ->execute(),
      'help' => match (TRUE) {
        !$types => new TranslatableMarkup('Turn on the Domain name trait for a product type first.'),
        $imports => new TranslatableMarkup('Add them from a list, a CSV file or a registrar account.'),
        default => new TranslatableMarkup('Add a product for each domain you sell, with the domain as its title.'),
      },
      'url' => match (TRUE) {
        !$types => Url::fromRoute('entity.commerce_product_type.collection'),
        // Commerce Domains Import's page.
        $imports => Url::fromRoute('commerce_domains_import.import'),
        default => Url::fromRoute('entity.commerce_product.add_page'),
      },
    ];

    $syncs = $this->moduleHandler->moduleExists('commerce_domains_marketplace');
    $connected = array_filter($providers, fn(ProviderInterface $provider) => $provider->status()
      && $provider->hasCapability(MarketplaceInterface::class)
      && $provider->getPlugin()->getSetupError() === NULL);
    $steps['marketplace'] = [
      'title' => new TranslatableMarkup('Connect a marketplace'),
      'done' => $syncs && $connected,
      'help' => $syncs
        ? new TranslatableMarkup('Add a marketplace such as Afternic and set its API keys, so its listings follow the catalog.')
        : new TranslatableMarkup('Install Commerce Domains Marketplace, then add a marketplace and set its API keys, so its listings follow the catalog.'),
      'url' => Url::fromRoute('entity.commerce_domains_provider.collection'),
    ];

    $payments = $this->entityTypeManager->hasDefinition('commerce_payment_gateway');
    $steps['payment'] = [
      'title' => new TranslatableMarkup('Enable a payment gateway'),
      'done' => $payments && $this->entityTypeManager->getStorage('commerce_payment_gateway')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', TRUE)
        ->range(0, 1)
        ->execute(),
      'help' => $payments
        ? new TranslatableMarkup('Buyers pay for the domains they buy here through it.')
        : new TranslatableMarkup('Install Commerce Payment, so buyers can pay for the domains they buy here.'),
      'url' => $payments ? Url::fromRoute('entity.commerce_payment_gateway.collection') : Url::fromRoute('system.modules_list'),
    ];

    $steps['lander'] = [
      'title' => new TranslatableMarkup('Point parked domains at /domain-lander'),
      'done' => (bool) $this->state->get(self::LANDER_KEY),
      'help' => new TranslatableMarkup("Forward your parked domains at their registrars to @url, so visitors land on the domain's page here. This step is done once the first visitor comes through.", [
        '@url' => Url::fromRoute('commerce_domains.lander', [], ['absolute' => TRUE])->toString(),
      ]),
      'url' => NULL,
    ];

    $steps['notify'] = [
      'title' => new TranslatableMarkup('Set the notification address'),
      'done' => (string) $this->configFactory->get('commerce_domains_owner.settings')->get('notify_mail') !== '',
      'help' => new TranslatableMarkup('Sales, failing syncs and expiring domains are mailed there. Until it is set, they go to the store email.'),
      'url' => Url::fromRoute('commerce_domains_owner.settings'),
    ];
    return $steps;
  }

}

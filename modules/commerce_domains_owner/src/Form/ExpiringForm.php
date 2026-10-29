<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_domains\Availability;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_domains_owner\Renewals;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the domains that expire soon, to keep them or let them expire.
 *
 * Deciding only changes the site: a domain left to expire goes off sale
 * shortly before its expiry date. Auto-renew changes at the registrar only
 * with its own buttons, one call per domain.
 */
final class ExpiringForm extends FormBase {

  // The windows offered above the list, in days.
  protected const WINDOWS = [30, 60, 90, 180, 365];

  // Domains whose auto-renew changes at once: each is a registrar call.
  public const MAX_REGISTRAR_CHANGES = 20;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Renewals $renewals,
    protected DateFormatterInterface $dateFormatter,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected TimeInterface $time,
    protected Availability $availability,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(Renewals::class),
      $container->get('date.formatter'),
      $container->get('commerce_price.currency_formatter'),
      $container->get('datetime.time'),
      $container->get(Availability::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_domains_owner_expiring';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $days = $this->days();
    $now = $this->time->getRequestTime();
    $links = [];
    foreach (self::WINDOWS as $window) {
      $links[$window] = [
        'title' => $this->formatPlural($window, '1 day', '@count days'),
        'url' => Url::fromRoute('commerce_domains_owner.expiring', [], ['query' => ['days' => $window]]),
      ];
    }
    $form['window'] = [
      '#type' => 'container',
      'text' => [
        '#markup' => '<p>' . $this->t('Domains that expire within @days days, and those that expired in the last 30 days, which registrars still renew. A domain you let expire goes off sale shortly before its expiry date.', ['@days' => $days]) . '</p>',
      ],
      'links' => [
        '#theme' => 'links',
        '#links' => $links,
        '#heading' => [
          'text' => $this->t('Show the domains expiring within'),
          'level' => 'h2',
          'class' => ['visually-hidden'],
        ],
        '#attributes' => ['class' => ['inline']],
      ],
    ];

    $query = $this->renewals->query($days);
    $ids = $query ? $query->pager(50)->execute() : [];
    $options = [];
    $costs = [];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($this->entityTypeManager->getStorage('commerce_product')->loadMultiple($ids) as $id => $product) {
      $expires = (int) $product->get('domain_expires')->value;
      $auto_renew = Renewals::autoRenews($product);
      $cost = Costs::price($product, 'domain_renewal_cost');
      $leaving = $product->hasField('domain_renewal') && $product->get('domain_renewal')->value === DomainCosts::EXPIRE;
      if ($cost && !$leaving) {
        $currency = $cost->getCurrencyCode();
        $costs[$currency] = isset($costs[$currency]) ? $costs[$currency]->add($cost) : $cost;
      }
      $options[$id] = [
        'domain' => ['data' => $product->toLink(NULL, 'edit-form')->toRenderable()],
        'registrar' => $product->get('domain_registrar')->entity?->label() ?? '',
        'expires' => $this->t('@date (@when)', [
          '@date' => $this->dateFormatter->format($expires, 'custom', 'Y-m-d'),
          '@when' => Renewals::when($expires, $now),
        ]),
        'auto_renew' => $auto_renew === NULL ? $this->t('Unknown') : ($auto_renew ? $this->t('On') : $this->t('Off')),
        'cost' => $cost ? $this->money($cost) : '',
        'decision' => Renewals::decisionLabel($product),
        'sale' => match (TRUE) {
          $this->forSale($product) => $this->t('For sale'),
          $this->renewals->isOffSale($product) => $this->t('Off sale: left to expire'),
          default => $this->t('Not for sale'),
        },
      ];
    }

    $form['domains'] = [
      '#type' => 'tableselect',
      '#header' => [
        'domain' => $this->t('Domain'),
        'registrar' => $this->t('Registrar'),
        'expires' => $this->t('Expires'),
        'auto_renew' => $this->t('Auto-renew'),
        'cost' => $this->t('Renewal cost'),
        'decision' => $this->t('Renewal'),
        'sale' => $this->t('On the site'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No domain expires within @days days.', ['@days' => $days]),
    ];
    $form['pager'] = ['#type' => 'pager'];
    if ($costs) {
      ksort($costs);
      $form['costs'] = [
        '#markup' => '<p>' . $this->t("Renewing the domains on this page that you keep or haven't decided on costs @amount.", [
          '@amount' => implode(' + ', array_map($this->money(...), $costs)),
        ]) . '</p>',
      ];
    }

    $account = $this->currentUser();
    $decides = $account->hasPermission('manage domain portfolio') && $this->renewals->hasCosts();
    $form['actions'] = [
      '#type' => 'actions',
      '#access' => (bool) $options,
    ];
    $form['actions']['keep'] = [
      '#type' => 'submit',
      '#value' => $this->t('Keep'),
      '#decision' => DomainCosts::KEEP,
      '#submit' => ['::decide'],
      '#access' => $decides,
    ];
    $form['actions']['expire'] = [
      '#type' => 'submit',
      '#value' => $this->t('Let expire'),
      '#decision' => DomainCosts::EXPIRE,
      '#submit' => ['::decide'],
      '#access' => $decides,
    ];
    // Each of these is a call to the registrar, made at once.
    $changes_registrar = $account->hasPermission('manage domain portfolio') && $account->hasPermission('manage domains at registrars');
    $form['actions']['auto_renew_on'] = [
      '#type' => 'submit',
      '#value' => $this->t('Turn auto-renew on at the registrar'),
      '#auto_renew' => TRUE,
      '#submit' => ['::autoRenew'],
      '#access' => $changes_registrar,
    ];
    $form['actions']['auto_renew_off'] = [
      '#type' => 'submit',
      '#value' => $this->t('Turn auto-renew off at the registrar'),
      '#auto_renew' => FALSE,
      '#submit' => ['::autoRenew'],
      '#access' => $changes_registrar,
    ];
    // The list moves with the date.
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = $this->selected($form_state);
    if (!$selected) {
      $form_state->setErrorByName('domains', $this->t('Select the domains first.'));
    }
    $trigger = $form_state->getTriggeringElement();
    if (isset($trigger['#auto_renew']) && count($selected) > self::MAX_REGISTRAR_CHANGES) {
      $form_state->setErrorByName('domains', $this->t('Change auto-renew for at most @max domains at a time: each is a call to the registrar.', ['@max' => self::MAX_REGISTRAR_CHANGES]));
    }
  }

  /**
   * Keeps the selected domains, or lets them expire.
   */
  public function decide(array &$form, FormStateInterface $form_state): void {
    $decision = $form_state->getTriggeringElement()['#decision'];
    $products = $this->selectedProducts($form_state);
    $this->renewals->decide($products, $decision);
    $count = count($products);
    if ($decision === DomainCosts::KEEP) {
      $this->messenger()->addStatus($this->formatPlural($count, 'Keeping 1 domain. Renew it at the registrar, or turn on its auto-renew.', 'Keeping @count domains. Renew them at the registrar, or turn on their auto-renew.'));
      return;
    }
    $days = (int) $this->config('commerce_domains_owner.settings')->get('off_sale_days');
    $this->messenger()->addStatus($this->formatPlural($count, 'Letting 1 domain expire. It goes off sale @days days before its expiry date; turn off its auto-renew so the registrar does not renew it.', 'Letting @count domains expire. They go off sale @days days before their expiry dates; turn off their auto-renew so the registrar does not renew them.', ['@days' => $days]));
  }

  /**
   * Turns auto-renew on or off at the registrar for the selected domains.
   */
  public function autoRenew(array &$form, FormStateInterface $form_state): void {
    $on = (bool) $form_state->getTriggeringElement()['#auto_renew'];
    $done = [];
    foreach ($this->selectedProducts($form_state) as $product) {
      try {
        $this->renewals->setAutoRenew($product, $on);
        $done[] = $product->label();
      }
      catch (ProviderException $e) {
        $args = ['@domain' => $product->label(), '@error' => $e->getMessage()];
        $this->messenger()->addError($this->t('@domain: @error', $args));
      }
    }
    if ($done) {
      $args = ['@domains' => implode(', ', $done)];
      $this->messenger()->addStatus($on ? $this->t('Auto-renew is on at the registrar for @domains.', $args) : $this->t('Auto-renew is off at the registrar for @domains.', $args));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Returns the window, from the query string or the settings.
   */
  protected function days(): int {
    $days = (int) $this->getRequest()->query->get('days');
    return $days > 0 ? min($days, 3650) : $this->renewals->days();
  }

  /**
   * Returns the IDs of the selected domains.
   *
   * @return int[]
   *   Product IDs.
   */
  protected function selected(FormStateInterface $form_state): array {
    return array_map('intval', array_keys(array_filter((array) $form_state->getValue('domains'))));
  }

  /**
   * Loads the selected domains.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface[]
   *   The products.
   */
  protected function selectedProducts(FormStateInterface $form_state): array {
    /** @var \Drupal\commerce_product\Entity\ProductInterface[] */
    return $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($this->selected($form_state));
  }

  /**
   * Whether any variation of a domain is for sale.
   */
  protected function forSale(ProductInterface $product): bool {
    foreach ($product->getVariations() as $variation) {
      if ($this->availability->isForSale($product, $variation)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Formats an amount.
   */
  protected function money(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}

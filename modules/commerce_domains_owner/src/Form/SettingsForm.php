<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\commerce_domains_owner\Notifier;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for running the portfolio: notifications and renewals.
 */
class SettingsForm extends ConfigFormBase {

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
    return 'commerce_domains_owner_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_domains_owner.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\commerce_store\StoreStorageInterface $stores */
    $stores = $this->entityTypeManager->getStorage('commerce_store');
    $store_mail = (string) $stores->loadDefault()?->getEmail();
    $form['notify_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Send owner notifications to'),
      '#description' => $store_mail !== ''
        ? $this->t("Leave empty for the store's email address, @mail.", ['@mail' => $store_mail])
        : $this->t("Leave empty for the store's email address."),
      '#config_target' => 'commerce_domains_owner.settings:notify_mail',
    ];
    $form['notify'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Notifications'),
      '#description' => $this->t('Offers have their own mails, set on the domain offers settings; they come to this address too, unless those settings name another.'),
    ];
    $kinds = [
      Notifier::SOLD => [
        $this->t('A domain sold on the site'),
        $this->t('With the order, and where the buyer wants the domain.'),
      ],
      Notifier::SOLD_ELSEWHERE => [
        $this->t('A marketplace sold a domain'),
        $this->t('When a marketplace sync finds a listing sold.'),
      ],
      Notifier::SYNC_FAILED => [
        $this->t('A marketplace sync failed'),
        $this->t('At most once a day for each marketplace.'),
      ],
      Notifier::EXPIRING => [
        $this->t('Domains expire soon'),
        $this->t('A daily digest of the domains not decided yet, those kept that do not renew automatically, and those left to expire that do.'),
      ],
      Notifier::REGISTRAR_MISSING => [
        $this->t('A registrar no longer holds a domain'),
        $this->t('When reading the whole account no longer finds a domain recorded there, unless it sold or you let it expire.'),
      ],
      Notifier::OFFER_EXPIRED => [
        $this->t('An offer expired'),
        $this->t('Unanswered, or accepted and not paid in time.'),
      ],
    ];
    foreach ($kinds as $kind => [$title, $description]) {
      $form['notify'][$kind] = [
        '#type' => 'checkbox',
        '#title' => $title,
        '#description' => $description,
        '#config_target' => 'commerce_domains_owner.settings:notify.' . $kind,
      ];
    }
    $form['expiring_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Expiring soon: within this many days'),
      '#description' => $this->t('For the Expiring soon page, the dashboard and the daily digest.'),
      '#min' => 1,
      '#max' => 3650,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains_owner.settings:expiring_days',
    ];
    $form['off_sale_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Take a domain you let expire off sale this many days before its expiry date'),
      '#description' => $this->t('Leave time for a sale to complete before the domain lapses. 0 keeps it on sale until its expiry date.'),
      '#min' => 0,
      '#max' => 3650,
      '#required' => TRUE,
      '#config_target' => 'commerce_domains_owner.settings:off_sale_days',
    ];
    return parent::buildForm($form, $form_state);
  }

}

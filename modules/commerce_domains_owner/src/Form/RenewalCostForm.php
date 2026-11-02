<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner\Form;

use Drupal\commerce_domains\Entity\ProviderInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\RegistrarInterface;
use Drupal\commerce_domains_owner\Entity\RenewalCost;
use Drupal\commerce_domains_owner\RenewalCosts;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a renewal cost: one for each extension and registrar.
 */
class RenewalCostForm extends ContentEntityForm {

  /**
   * The table of renewal costs.
   *
   * @var \Drupal\commerce_domains_owner\RenewalCosts
   */
  protected RenewalCosts $costs;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->costs = $container->get(RenewalCosts::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    // Only those that hold domains, and "any" first.
    $options = ['_none' => $this->t('Any registrar')];
    foreach ($this->entityTypeManager->getStorage('commerce_domains_provider')->loadMultiple() as $id => $provider) {
      if ($provider instanceof ProviderInterface && $provider->hasCapability(RegistrarInterface::class)) {
        $options[$id] = $provider->label();
      }
    }
    $form['provider']['widget']['#options'] = $options;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    $tld = RenewalCost::normalize((string) $form_state->getValue(['tld', 0, 'value']));
    if ($tld === '') {
      return $entity;
    }
    if (!RenewalCost::isValid($tld)) {
      $form_state->setErrorByName('tld', $this->t('Write the extension like com or co.uk.'));
      return $entity;
    }
    $provider = $form_state->getValue(['provider', 0, 'target_id']) ?: NULL;
    $other = $this->costs->find($tld, $provider);
    if ($other && $other->id() !== $this->entity->id()) {
      $form_state->setErrorByName('tld', $this->t('%tld already has a cost for that registrar: change that one.', ['%tld' => $tld]));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $saved = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the renewal cost of %tld.', ['%tld' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $saved;
  }

}

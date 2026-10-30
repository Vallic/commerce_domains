<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Appraisal;
use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\AppraisalInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Appraises every domain at 100 dollars per letter, up to a daily limit.
 *
 * State keys, under commerce_domains_test.: appraise_limit, the appraisals
 * allowed before it throws; appraised, the domains appraised so far.
 */
#[CommerceDomainsProvider(
  id: 'test_appraiser',
  label: new TranslatableMarkup('Test appraiser'),
)]
final class TestAppraiser extends ProviderPluginBase implements AppraisalInterface {

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function appraise(string $name): Appraisal {
    $appraised = $this->state->get('commerce_domains_test.appraised', []);
    if (count($appraised) >= $this->state->get('commerce_domains_test.appraise_limit', PHP_INT_MAX)) {
      throw new ProviderException('Daily rate limit exceeded.', TRUE);
    }
    $appraised[] = $name;
    $this->state->set('commerce_domains_test.appraised', $appraised);
    $label = strstr($name, '.', TRUE) ?: $name;
    return new Appraisal($name, 100 * strlen($label), 7.5, 3, ['Short'], ['Hyphen']);
  }

}

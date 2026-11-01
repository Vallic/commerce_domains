<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider;

use Drupal\commerce_domains\Attribute\CommerceDomainsProvider;
use Drupal\commerce_domains\PaymentLink;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\PaymentLinksInterface;
use Drupal\commerce_domains\Plugin\CommerceDomains\Provider\ProviderPluginBase;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_price\Price;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Makes payment links in dollars, kept in State.
 *
 * State keys under commerce_domains_test.:
 * - payment_links: the links made, keyed by ID: name, price, email, options.
 * - payment_link_states: the state to report, keyed by ID; PENDING without.
 * - payment_link_refuse: a domain whose link is refused.
 */
#[CommerceDomainsProvider(
  id: 'test_payment_links',
  label: new TranslatableMarkup('Test payment links'),
)]
final class TestPaymentLinks extends ProviderPluginBase implements PaymentLinksInterface {

  protected const PREFIX = 'commerce_domains_test.';

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
  public function paymentLinkCurrencies(): array {
    return ['USD'];
  }

  /**
   * {@inheritdoc}
   */
  public function paymentLinkMinimum(): float {
    return 100.0;
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentLink(string $name, Price $price, string $buyer_email, array $options = []): PaymentLink {
    if ($this->state->get(self::PREFIX . 'payment_link_refuse') === $name) {
      throw new ProviderException('The domain is not listed.');
    }
    $links = $this->state->get(self::PREFIX . 'payment_links', []);
    $id = 'link-' . (count($links) + 1);
    $links[$id] = [
      'name' => $name,
      'price' => [$price->getNumber(), $price->getCurrencyCode()],
      'email' => $buyer_email,
      'options' => $options,
    ];
    $this->state->set(self::PREFIX . 'payment_links', $links);
    return $this->fetchPaymentLink($id);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPaymentLink(string $id): PaymentLink {
    $state = $this->state->get(self::PREFIX . 'payment_link_states', [])[$id] ?? PaymentLink::PENDING;
    return new PaymentLink($id, 'https://pay.example.com/' . $id, $state, $state);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_offer\OfferManager;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\EventSubscriber\OfferSubscriber;
use Drupal\commerce_domains_owner\EventSubscriber\OrderSubscriber;
use Drupal\commerce_domains_owner\Form\SettingsForm;
use Drupal\commerce_domains_owner\Hook\OwnerHooks;
use Drupal\commerce_domains_owner\Notifier;
use Drupal\commerce_domains_owner\Plugin\Commerce\EntityTrait\DomainCosts;
use Drupal\commerce_domains_owner\SaleRecorder;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the owner notifications about sales, renewals, registrars, offers.
 */
#[CoversClass(Notifier::class)]
#[CoversClass(SaleRecorder::class)]
#[CoversClass(OrderSubscriber::class)]
#[CoversClass(OfferSubscriber::class)]
#[CoversClass(OwnerHooks::class)]
#[CoversClass(SettingsForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class NotificationsTest extends OwnerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_cart',
    'commerce_checkout',
    'commerce_domains_checkout',
    'commerce_domains_offer',
  ];

  /**
   * The notifier.
   *
   * @var \Drupal\commerce_domains_owner\Notifier
   */
  protected Notifier $notifier;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_domains_offer');
    $this->installConfig(['commerce_checkout', 'commerce_domains_offer']);
    $this->notifier = $this->container->get(Notifier::class);
  }

  /**
   * Returns the body of a mail as one string.
   */
  protected static function body(array $mail): string {
    $body = is_array($mail['body']) ? implode("\n", $mail['body']) : (string) $mail['body'];
    // Undo the wrapping of long lines.
    return preg_replace('/ +\n/', ' ', $body);
  }

  /**
   * Tests where notifications go.
   */
  public function testAddress(): void {
    $this->assertSame($this->store->getEmail(), $this->notifier->address(), "The store's address by default.");
    $this->config('commerce_domains_owner.settings')->set('notify_mail', 'owner@example.com')->save();
    $this->assertSame('owner@example.com', $this->notifier->address());

    // Offers go there too, unless the offer settings name an address.
    $manager = $this->container->get(OfferManager::class);
    $buyer = ['mail' => 'buyer@example.com', 'name' => 'Ana'];
    $offer = fn(string $title) => $manager->submit($this->variation($this->createDomain($title, 2000)), new Price('1500', 'USD'), $buyer, new AnonymousUserSession());
    $new_offers = fn(string $to) => $this->getMails([
      'module' => 'commerce_domains_offer',
      'key' => 'new_offer',
      'to' => $to,
    ]);
    $offer('Offered.com');
    $this->assertCount(1, $new_offers('owner@example.com'));
    $this->config('commerce_domains_offer.settings')->set('notify_mail', 'offers@example.com')->save();
    $offer('Again.com');
    $this->assertCount(1, $new_offers('offers@example.com'));
  }

  /**
   * Tests the settings form.
   */
  public function testSettingsForm(): void {
    $form_state = (new FormState())->setValues([
      'notify_mail' => 'owner@example.com',
      Notifier::SOLD => 1,
      Notifier::SOLD_ELSEWHERE => 1,
      // A programmed form clears a checkbox with NULL.
      Notifier::SYNC_FAILED => NULL,
      Notifier::EXPIRING => 1,
      Notifier::REGISTRAR_MISSING => 1,
      Notifier::OFFER_EXPIRED => NULL,
      'expiring_days' => 30,
      'off_sale_days' => 7,
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('commerce_domains_owner.settings');
    $this->assertSame('owner@example.com', $config->get('notify_mail'));
    $this->assertFalse($config->get('notify.sync_failed'));
    $this->assertTrue($config->get('notify.sold'));
    $this->assertSame([30, 7], [$config->get('expiring_days'), $config->get('off_sale_days')]);
    $this->assertFalse($this->notifier->isOn(Notifier::OFFER_EXPIRED));
  }

  /**
   * Tests a sale on the site: the record, the mail, and cancelling.
   */
  public function testSoldOnSite(): void {
    // Placed orders wait for validation, and can be cancelled meanwhile.
    OrderType::load('default')->setWorkflowId('order_default_validation')->save();
    $this->createProvider('dynadot');
    // Left from a lease to own the buyer stopped paying.
    $sold = $this->createDomain('Sold.com', 1500, ['domain_registrar' => 'dynadot', 'domain_lease_months' => 12]);
    $other = $this->createDomain('Other.com', 500);
    $order = $this->createOrder([$sold, $other]);
    (new Transfer(Transfer::PUSH, 'ana-at-dynadot'))->toOrder($order);
    $this->transition($order, 'place');

    $mails = $this->ownerMails(Notifier::SOLD);
    $this->assertCount(1, $mails);
    $this->assertSame($this->store->getEmail(), $mails[0]['to']);
    $this->assertSame('Sold: 2 domains in order ' . $order->getOrderNumber(), (string) $mails[0]['subject']);
    $body = self::body($mails[0]);
    $this->assertStringContainsString('- Sold.com for $1,500.00, registered at Dynadot', $body);
    $this->assertStringContainsString('- Other.com for $500.00', $body);
    $this->assertStringContainsString('Buyer: buyer@example.com', $body);
    $this->assertStringContainsString('Push it to the account ana-at-dynadot at Dynadot.', $body);
    $this->assertStringContainsString($order->toUrl('canonical', ['absolute' => TRUE])->toString(), $body);

    $product = $this->reload($sold);
    $this->assertSame((int) $order->getPlacedTime(), (int) $product->get('domain_sold')->value);
    $this->assertEquals(new Price('1500', 'USD'), Costs::price($product, 'domain_sale_price'));
    $this->assertSame((int) $order->id(), (int) $product->get('domain_sale_order')->value);
    $this->assertTrue($product->get('domain_sold_via')->isEmpty());
    $this->assertTrue($product->get('domain_lease_months')->isEmpty(), 'Orders are paid at once.');

    // Cancelling the order undoes the sale.
    $this->transition(Order::load($order->id()), 'cancel');
    $product = $this->reload($sold);
    foreach (['domain_sold', 'domain_sale_price', 'domain_lease_months', 'domain_sale_order'] as $field) {
      $this->assertTrue($product->get($field)->isEmpty(), $field);
    }

    // Turned off, the sale is still recorded, without a mail.
    $this->config('commerce_domains_owner.settings')->set('notify.sold', FALSE)->save();
    $third = $this->createDomain('Third.com', 700);
    $order = $this->createOrder([$third], new Price('650', 'USD'));
    $this->transition($order, 'place');
    $this->assertCount(1, $this->ownerMails(Notifier::SOLD));
    $this->assertEquals(new Price('650', 'USD'), Costs::price($this->reload($third), 'domain_sale_price'), 'An agreed price wins.');
  }

  /**
   * Tests an offer agreed on Escrow.com, which sells before it is paid.
   */
  public function testSoldThroughEscrow(): void {
    $product = $this->createDomain('Escrow.com', 3000);
    $order = $this->createOrder([$product]);
    $workflow = $order->getState()->getWorkflow();
    $event = new WorkflowTransitionEvent($workflow->getTransition('place'), $workflow, $order, 'state');
    $subscriber = $this->container->get(OrderSubscriber::class);
    $subscriber->onOfferAccepted($event);
    $this->assertSame($this->now, (int) $this->reload($product)->get('domain_sold')->value);
    $this->assertCount(1, $this->ownerMails(Notifier::SOLD));

    // Escrow.com settles a lower price before approving the payment.
    $item = $order->getItems()[0];
    $item->setUnitPrice(new Price('2800', 'USD'), TRUE)->save();
    $subscriber->onPaymentApproved($event);
    $this->assertEquals(new Price('2800', 'USD'), Costs::price($this->reload($product), 'domain_sale_price'));
    $this->assertCount(1, $this->ownerMails(Notifier::SOLD), 'The price update sends nothing.');
  }

  /**
   * Tests the daily digest of expiring domains.
   */
  public function testDigest(): void {
    $day = self::DAY;
    $this->createDomain('Undecided.com', 1000, ['domain_expires' => $this->now + 10 * $day, 'domain_auto_renew' => TRUE]);
    $this->createDomain('Manual.com', 1000, [
      'domain_expires' => $this->now + 20 * $day,
      'domain_renewal' => DomainCosts::KEEP,
      'domain_auto_renew' => FALSE,
      'domain_renewal_cost' => new Price('10', 'USD'),
    ]);
    $this->createDomain('Automatic.com', 1000, [
      'domain_expires' => $this->now + 15 * $day,
      'domain_renewal' => DomainCosts::KEEP,
      'domain_auto_renew' => TRUE,
    ]);
    $this->createDomain('Leaving.com', 1000, [
      'domain_expires' => $this->now + 40 * $day,
      'domain_renewal' => DomainCosts::EXPIRE,
    ]);
    $this->createDomain('Charged.com', 1000, [
      'domain_expires' => $this->now + 30 * $day,
      'domain_renewal' => DomainCosts::EXPIRE,
      'domain_auto_renew' => TRUE,
    ]);
    $this->createDomain('Far.com', 1000, ['domain_expires' => $this->now + 100 * $day]);

    $this->container->get('cron')->run();
    $mails = $this->ownerMails(Notifier::EXPIRING);
    $this->assertCount(1, $mails);
    $this->assertSame('3 domains expire within 60 days', (string) $mails[0]['subject']);
    $body = self::body($mails[0]);
    $this->assertStringContainsString("Decide whether to keep these:\n- Undecided.com: expires ", $body);
    $this->assertStringContainsString('(in 10 days) at no registrar recorded, auto-renew on.', $body);
    $this->assertStringContainsString("they don't renew automatically:\n- Manual.com: expires ", $body);
    $this->assertStringContainsString('auto-renew off, renews for $10.00.', $body);
    $this->assertStringContainsString("but the registrar renews them.\n- Charged.com: expires ", $body);
    $this->assertStringNotContainsString('Automatic.com', $body);
    $this->assertStringNotContainsString('Leaving.com', $body);
    $this->assertStringNotContainsString('Far.com', $body);

    $this->assertFalse($this->notifier->digest(), 'Once a day.');
    // The next day it comes again, until nothing needs the owner.
    $state = $this->container->get('state');
    $state->set(Notifier::DIGEST_KEY, '2000-01-01');
    $this->assertTrue($this->notifier->digest());
    $titles = ['Undecided.com', 'Manual.com', 'Charged.com'];
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadByProperties(['title' => $titles]);
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    foreach ($products as $product) {
      $product->set('domain_renewal', DomainCosts::KEEP)->set('domain_auto_renew', TRUE)->save();
    }
    $state->set(Notifier::DIGEST_KEY, '2000-01-01');
    $this->assertFalse($this->notifier->digest());
    $this->assertCount(2, $this->ownerMails(Notifier::EXPIRING));
  }

  /**
   * Tests mailing the domains a registrar no longer holds.
   */
  public function testRegistrarMissing(): void {
    $provider = $this->createProvider('reg');
    foreach (['Kept.com', 'Stolen.com'] as $title) {
      $this->createDomain($title, 1000, ['domain_registrar' => 'reg']);
    }
    $this->createDomain('Sold.com', 1000, [
      'domain_registrar' => 'reg',
      'domain_sold' => $this->now - self::DAY,
      'domain_sale_price' => new Price('900', 'USD'),
    ]);
    $this->createDomain('Lapsed.com', 1000, [
      'domain_registrar' => 'reg',
      'domain_renewal' => DomainCosts::EXPIRE,
      'domain_expires' => $this->now - self::DAY,
    ]);
    $state = $this->container->get('state');
    $state->set('commerce_domains_test.registrations', ['kept.com' => $this->now + 100 * self::DAY]);
    $this->container->get(RegistrarSync::class)->plan($provider);

    $this->assertSame(1, $this->notifier->registrarChecks());
    $mails = $this->ownerMails(Notifier::REGISTRAR_MISSING);
    $this->assertSame('Reg no longer holds 1 domain', (string) $mails[0]['subject']);
    $body = self::body($mails[0]);
    $this->assertStringContainsString("- stolen.com\n", $body);
    $this->assertStringNotContainsString('sold.com', $body, 'A sold domain is meant to go.');
    $this->assertStringNotContainsString('lapsed.com', $body, 'So is one left to expire.');
    $this->assertStringContainsString($provider->toUrl('registrations', ['absolute' => TRUE])->toString(), $body);

    // The same read is no news; a later one names only the newly missing.
    $this->assertSame(0, $this->notifier->registrarChecks());
    $state->set(RegistrarSync::CHECKED_KEY . 'reg', [
      'time' => $this->now + 60,
      'missing' => ['kept.com', 'lapsed.com', 'sold.com', 'stolen.com'],
    ]);
    $this->assertSame(1, $this->notifier->registrarChecks());
    $mails = $this->ownerMails(Notifier::REGISTRAR_MISSING);
    $this->assertStringContainsString('- kept.com', self::body($mails[1]));
    $this->assertStringNotContainsString('stolen.com', self::body($mails[1]));

    // Installing takes what is known as seen.
    $state->set(RegistrarSync::CHECKED_KEY . 'reg', ['time' => $this->now + 120, 'missing' => ['other.com']]);
    $this->container->get('module_handler')->loadInclude('commerce_domains_owner', 'install');
    commerce_domains_owner_install(TRUE);
    $this->assertSame(0, $this->notifier->registrarChecks());
  }

  /**
   * Tests mailing the owner when an offer expires.
   */
  public function testOfferExpired(): void {
    $manager = $this->container->get(OfferManager::class);
    $buyer = ['mail' => 'buyer@example.com', 'name' => 'Ana'];
    $unanswered = $manager->submit($this->variation($this->createDomain('Quiet.com', 2000)), new Price('1500', 'USD'), $buyer, new AnonymousUserSession());
    $unpaid = $manager->submit($this->variation($this->createDomain('Unpaid.com', 2000)), new Price('1800', 'USD'), $buyer, new AnonymousUserSession());
    $manager->accept($unpaid);
    $this->container->get('database')->update('commerce_domains_offer')
      ->fields(['changed' => $this->now - 30 * self::DAY])
      ->condition('offer_id', [$unanswered->id(), $unpaid->id()], 'IN')
      ->execute();
    $this->entityTypeManager->getStorage('commerce_domains_offer')->resetCache();

    $this->assertSame(2, $manager->expireStale());
    $mails = $this->ownerMails(Notifier::OFFER_EXPIRED);
    $this->assertSame(['Offer on quiet.com expired', 'Offer on unpaid.com expired'], array_map('strval', array_column($mails, 'subject')));
    $this->assertStringContainsString('Nobody answered the offer of $1,500.00 from Ana for quiet.com in time', self::body($mails[0]));
    $this->assertStringContainsString("Ana didn't pay for unpaid.com in time", self::body($mails[1]));
    $this->assertStringNotContainsString($unpaid->getToken(), self::body($mails[1]), "The buyer's link stays with the buyer.");
  }

}

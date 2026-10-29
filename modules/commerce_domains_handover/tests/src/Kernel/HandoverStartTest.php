<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce\MailHandlerInterface;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Entity\Handover;
use Drupal\commerce_domains_handover\EventSubscriber\OrderSubscriber;
use Drupal\commerce_domains_handover\Form\HandoverStartForm;
use Drupal\commerce_domains_handover\HandoverMail;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests when handovers start, and the buyer's first email.
 */
#[CoversClass(OrderSubscriber::class)]
#[CoversClass(HandoverManager::class)]
#[CoversClass(HandoverMail::class)]
#[CoversClass(Handover::class)]
#[CoversClass(HandoverStartForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class HandoverStartTest extends HandoverKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_escrow',
    'commerce_domains_offer_escrow_test',
  ];

  /**
   * Tests that payment in full starts the handover, once.
   */
  public function testStartOnPayment(): void {
    $order = $this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::PUSH, 'ana@example.com'));
    // A fee beside the domain gets no handover.
    $fee = OrderItem::create([
      'type' => 'default',
      'title' => 'Escrow fee',
      'unit_price' => new Price('20', 'USD'),
      'quantity' => 1,
    ]);
    $fee->save();
    $order->addItem($fee)->save();
    $this->assertSame([], $this->manager->forOrder($order), 'Placing alone starts nothing.');
    $this->assertSame([], $this->handoverMails());

    $this->pay($order);
    $handover = $this->handover($order);
    $this->assertSame('waiting', $handover->getState()->getId());
    $this->assertSame('example.com', $handover->getDomain());
    $this->assertSame('dynadot', $handover->getRegistrar()->id());
    $this->assertEquals(new Transfer(Transfer::PUSH, 'ana@example.com'), $handover->getTransfer());
    $this->assertTrue($handover->isOpen());
    $this->assertSame([
      'commerce_domains_handover_start',
      'mail_commerce_domains_handover_started',
    ], array_values(array_filter($this->activity($order), fn(string $id) => str_contains($id, 'handover'))));

    // What happens next, from the store, for a push.
    $mails = $this->handoverMails(self::BUYER);
    $this->assertCount(1, $mails);
    $this->assertSame('What happens next with example.com', (string) $mails[0]['subject']);
    $this->assertSame('commerce_commerce_domains_handover_started', $mails[0]['id']);
    $this->assertSame('Default store <admin@example.com>', $mails[0]['from']);
    $this->assertStringContainsString('We push example.com to your Dynadot account, ana@example.com.', $this->body($mails[0]));
    $this->assertStringContainsString('If Dynadot asks you to accept the domain, accept it in your account.', $this->body($mails[0]));

    // Later saves start nothing more.
    $order->setData('seen', TRUE)->save();
    $this->pay($order);
    $this->assertCount(1, $this->manager->forOrder($order));
    $this->assertCount(1, $this->handoverMails());

    // Deleting the order deletes its handover.
    $id = $handover->id();
    $order->delete();
    $this->entityTypeManager->getStorage('commerce_domains_handover')->resetCache();
    $this->assertNull(Handover::load($id));
  }

  /**
   * Tests an order paid before it is placed, and a buyer never asked.
   */
  public function testStartOnPlacing(): void {
    // Paid while still a draft: the handover waits for the order.
    $product = $this->createDomain('Early.com', 1000, ['domain_registrar' => 'dynadot']);
    $variations = $product->getVariations();
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $item_storage */
    $item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $item = $item_storage->createFromPurchasableEntity(reset($variations));
    $item->save();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'mail' => self::BUYER,
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    (new Transfer(Transfer::TRANSFER, registrar: 'Namecheap'))->toOrder($order);
    $order->save();
    $this->pay($order);
    $this->assertSame([], $this->manager->forOrder($order));
    $order->getState()->applyTransitionById('place');
    $order->save();
    $this->assertSame('waiting', $this->handover($order)->getState()->getId());

    // A transfer's next steps name the buyer's registrar.
    $body = $this->body($this->handoverMails(self::BUYER)[0]);
    $this->assertStringContainsString('We unlock early.com and email you its auth code', $body);
    $this->assertStringContainsString('Start a transfer at Namecheap with that code.', $body);

    // A buyer who wasn't asked is asked by email.
    $this->container->get('state')->set('system.test_mail_collector', []);
    $asked = $this->pay($this->placeOrder('Unasked.com'));
    $this->assertCount(1, $this->manager->forOrder($asked));
    $body = $this->body($this->handoverMails(self::BUYER)[0]);
    $this->assertStringContainsString('We need to know where unasked.com should go.', $body);
    $this->assertStringContainsString('Reply to this email with your Dynadot account to push it to', $body);
  }

  /**
   * Tests that a handover failing to start doesn't fail the payment.
   */
  public function testFailureSparesPayment(): void {
    // Mail that throws, as a broken transport can, inside the order's save.
    $broken = new class() implements MailHandlerInterface {

      /**
       * {@inheritdoc}
       */
      public function sendMail($to, $subject, array $body, array $params = []) {
        throw new \RuntimeException('Mail is down.');
      }

    };
    $this->container->set(HandoverManager::class, new HandoverManager(
      $this->entityTypeManager,
      new HandoverMail($broken),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.commerce_domains_handover'),
    ));
    $order = $this->pay($this->placeOrder('Example.com'));
    $this->entityTypeManager->getStorage('commerce_order')->resetCache();
    $this->assertTrue(Order::load($order->id())->isPaid(), 'The payment is saved.');
    $errors = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('type', 'commerce_domains_handover')
      ->execute()
      ->fetchCol();
    $this->assertContains('Starting the handover of paid order @order failed: @error. Check its Handover tab.', $errors);
  }

  /**
   * Tests that Escrow.com sales are left to Escrow.com.
   */
  public function testEscrowSkipped(): void {
    PaymentGateway::create([
      'id' => 'escrow',
      'label' => 'Escrow.com',
      'plugin' => 'test_escrow_offer',
      'status' => TRUE,
    ])->save();
    $order = $this->pay($this->placeOrder('Escrowed.com', 'dynadot', NULL, ['payment_gateway' => 'escrow']));
    $this->assertTrue($this->manager->isEscrow($order));
    $this->assertSame([], $this->manager->forOrder($order));
    $this->assertSame([], $this->handoverMails());

    // The seller may still start it by hand.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$order]);
    $form = $this->container->get('form_builder')->buildForm(HandoverStartForm::class, $form_state);
    $this->assertStringContainsString('Escrow.com runs the handover of this order', (string) $form['description']['#markup']);
    $this->container->get('form_builder')->submitForm(HandoverStartForm::class, $form_state);
    $this->assertSame('waiting', $this->handover($order)->getState()->getId());
    $this->assertContains('commerce_domains_handover_start', $this->activity($order));
    $this->assertSame(['What happens next with escrowed.com'], $this->subjects());
  }

  /**
   * Tests starting by hand, and canceling with the order.
   */
  public function testManualStartAndCancel(): void {
    // Orders go through validation, so a placed one can still be canceled.
    OrderType::load('default')->setWorkflowId('order_default_validation')->save();
    $order = $this->placeOrder('Manual.com', 'dynadot', new Transfer(Transfer::PUSH, 'ana'));
    $form = HandoverStartForm::create($this->container);
    $this->assertTrue($form->access($order)->isAllowed(), 'Unpaid, but the seller may start it.');

    /** @var \Drupal\commerce_order\Entity\OrderInterface $draft */
    $draft = $this->entityTypeManager->getStorage('commerce_order')->create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
    ]);
    $draft->save();
    $this->assertFalse($form->access($draft)->isAllowed(), 'Not for drafts.');

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$order]);
    $this->container->get('form_builder')->submitForm(HandoverStartForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertCount(1, $this->manager->forOrder($order));
    $this->assertContains('commerce_domains_handover_start', $this->activity($order));
    $this->assertFalse(HandoverStartForm::create($this->container)->access($order)->isAllowed(), 'Started once.');

    // Paying later starts no second one.
    $this->pay($order);
    $this->assertCount(1, $this->manager->forOrder($order));

    $order->getState()->applyTransitionById('cancel');
    $order->save();
    $handover = $this->handover($order);
    $this->assertSame('canceled', $handover->getState()->getId());
    $this->assertFalse($handover->isOpen());
    $this->assertContains('commerce_domains_handover_cancel', $this->activity($order));
    $this->assertSame(['What happens next with manual.com'], $this->subjects(), 'Canceling emails nobody.');
  }

}

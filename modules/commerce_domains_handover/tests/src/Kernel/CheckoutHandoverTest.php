<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce_domains\Form\DomainRegistrarForm;
use Drupal\commerce_domains_checkout\Plugin\Commerce\CheckoutPane\DomainTransfer;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Controller\HandoverController;
use Drupal\commerce_domains_handover\Hook\RegistrarTabHooks;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the handover of what the buyer answered in the domain checkout.
 *
 * The checkout pane keeps the answer on the order as a Transfer; the
 * handover reads it back for the seller's steps and the buyer's emails.
 */
#[CoversClass(DomainTransfer::class)]
#[CoversClass(Transfer::class)]
#[CoversClass(HandoverController::class)]
#[CoversClass(RegistrarTabHooks::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class CheckoutHandoverTest extends HandoverKernelTestBase {

  /**
   * Creates a draft order for a domain.
   */
  protected function draftOrder(ProductInterface $product): OrderInterface {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $variations = $product->getVariations();
    $item = $storage->createFromPurchasableEntity(reset($variations));
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => 0,
      'mail' => self::BUYER,
      'state' => 'draft',
      'order_items' => [$item],
    ]);
    $order->save();
    return $order;
  }

  /**
   * Answers the checkout's Domain transfer pane, as the buyer would.
   */
  protected function answer(OrderInterface $order, array $values): void {
    $flow = $this->container->get('plugin.manager.commerce_checkout_flow')->createInstance('commerce_domains');
    $flow->setOrder($order);
    $pane = $this->container->get('plugin.manager.commerce_checkout_pane')->createInstance('commerce_domains_transfer', [], $flow);
    $complete_form = [];
    $form_state = new FormState();
    $pane_form = $pane->buildPaneForm(['#parents' => ['commerce_domains_transfer']], $form_state, $complete_form);
    foreach (['method', 'account', 'registrar'] as $key) {
      $pane_form[$key]['#parents'] = ['commerce_domains_transfer', $key];
    }
    $form_state->setValue('commerce_domains_transfer', $values + ['account' => '', 'registrar' => '']);
    $pane->validatePaneForm($pane_form, $form_state, $complete_form);
    $this->assertSame([], $form_state->getErrors());
    $pane->submitPaneForm($pane_form, $form_state, $complete_form);
    $order->save();
  }

  /**
   * Places an order and records it paid in full.
   */
  protected function placeAndPay(OrderInterface $order): OrderInterface {
    $order->getState()->applyTransitionById('place');
    $order->save();
    return $this->pay($order);
  }

  /**
   * Renders a render array to text.
   */
  protected function text(array $build): string {
    return preg_replace('/\s+/', ' ', strip_tags((string) $this->container->get('renderer')->renderInIsolation($build)));
  }

  /**
   * Tests a push and a transfer, from the pane to the handover.
   */
  public function testCheckoutAnswer(): void {
    $this->setCurrentUser($this->createUser(['administer commerce_order', 'hand over domains']));
    $controller = HandoverController::create($this->container);

    $order = $this->draftOrder($this->createDomain('Example.com', 1000, ['domain_registrar' => 'dynadot']));
    $this->answer($order, ['method' => Transfer::PUSH, 'account' => 'ana@example.com']);
    $handover = $this->handover($this->placeAndPay($order));
    $this->assertEquals(new Transfer(Transfer::PUSH, 'ana@example.com'), $handover->getTransfer());
    // The buyer's answer reads the same at checkout, on the order and to the
    // seller.
    $answer = 'Push to the Dynadot account: ana@example.com';
    $this->assertSame($answer, $order->getCustomerComments());
    $this->assertStringContainsString($answer, $this->text($controller->page($order)));
    $mails = $this->handoverMails(self::BUYER);
    $this->assertCount(1, $mails);
    $this->assertStringContainsString('We push example.com to your Dynadot account, ana@example.com.', $this->body($mails[0]));

    // A transfer elsewhere goes by the auth code, its steps first.
    $order = $this->draftOrder($this->createDomain('Refuse.com', 1000, ['domain_registrar' => 'dynadot']));
    $this->answer($order, ['method' => Transfer::TRANSFER, 'registrar' => 'Namecheap']);
    $handover = $this->handover($this->placeAndPay($order));
    $this->assertEquals(new Transfer(Transfer::TRANSFER, registrar: 'Namecheap'), $handover->getTransfer());
    $page = $controller->page($order);
    $this->assertStringContainsString('Transfer to Namecheap', $this->text($page));
    $steps = array_values(array_filter(array_keys($page['handover_' . $handover->id()]['steps']), fn($key) => $key[0] !== '#'));
    $this->assertSame(['unlock', 'send_code'], array_slice($steps, 0, 2));
    $mails = $this->handoverMails(self::BUYER);
    $this->assertCount(2, $mails);
    $this->assertStringContainsString('Here is how refuse.com moves to Namecheap.', $this->body($mails[1]));
  }

  /**
   * Tests that the domain's Registrar tab points to its open handover.
   */
  public function testRegistrarTab(): void {
    $product = $this->createDomain('Example.com', 1000, ['domain_registrar' => 'dynadot']);
    $order = $this->draftOrder($product);
    $this->answer($order, ['method' => Transfer::PUSH, 'account' => 'ana']);
    $this->placeAndPay($order);
    $form_builder = $this->container->get('form_builder');
    $seller = ['administer commerce_order', 'hand over domains', 'manage domains at registrars'];

    $this->setCurrentUser($this->createUser($seller));
    $form = $form_builder->getForm(DomainRegistrarForm::class, $product);
    $this->assertArrayHasKey('handover', $form);
    $html = (string) $this->container->get('renderer')->renderInIsolation($form['handover']);
    $this->assertStringContainsString('example.com is sold and waiting to be handed over', $html);
    $this->assertStringContainsString('href="/admin/commerce/orders/' . $order->id() . '/handover"', $html);
    $this->assertArrayHasKey('details', $form, 'The tab still reads the registrar.');

    // Someone who can't see handovers isn't pointed there.
    $this->setCurrentUser($this->createUser(['administer commerce_order', 'manage domains at registrars']));
    $this->assertArrayNotHasKey('handover', $form_builder->getForm(DomainRegistrarForm::class, $product));

    // Once the handover is closed, the tab is the registrar's alone again.
    // (Marking it transferred also takes the registrar off the product.)
    $this->setCurrentUser($this->createUser($seller));
    $this->manager->cancel($this->handover($order));
    $form = $form_builder->getForm(DomainRegistrarForm::class, $this->reloadProduct($product));
    $this->assertArrayNotHasKey('handover', $form);
  }

}

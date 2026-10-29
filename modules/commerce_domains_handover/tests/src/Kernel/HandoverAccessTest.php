<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Controller\HandoverController;
use Drupal\commerce_domains_handover\Form\HandoverStartForm;
use Drupal\commerce_domains_handover\Form\HandoverStepForm;
use Drupal\commerce_domains_handover\HandoverAccessControlHandler;
use Drupal\commerce_domains_handover\HandoverListBuilder;
use Drupal\commerce_order\Entity\Order;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests who sees handovers and who takes steps, and the pages.
 */
#[CoversClass(HandoverController::class)]
#[CoversClass(HandoverStepForm::class)]
#[CoversClass(HandoverStartForm::class)]
#[CoversClass(HandoverListBuilder::class)]
#[CoversClass(HandoverAccessControlHandler::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class HandoverAccessTest extends HandoverKernelTestBase {

  /**
   * Whether an account may open a route.
   */
  protected function allowed(string $route, array $parameters, AccountInterface $account): bool {
    return $this->container->get('access_manager')->checkNamedRoute($route, $parameters, $account);
  }

  /**
   * Renders a render array to text.
   */
  protected function text(array $build): string {
    return preg_replace('/\s+/', ' ', strip_tags((string) $this->container->get('renderer')->renderInIsolation($build)));
  }

  /**
   * Tests the permissions on the tab, the list and the steps.
   */
  public function testAccess(): void {
    $nobody = $this->createUser(['administer commerce_order']);
    $viewer = $this->createUser(['administer commerce_order', 'view domain handovers']);
    $seller = $this->createUser(['administer commerce_order', 'hand over domains']);
    $stranger = $this->createUser(['hand over domains']);

    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::TRANSFER, registrar: 'Namecheap')));
    $handover = $this->handover($order);
    $tab = ['commerce_order' => $order->id()];
    $this->assertFalse($this->allowed('commerce_domains_handover.order', $tab, $nobody));
    $this->assertTrue($this->allowed('commerce_domains_handover.order', $tab, $viewer));
    $this->assertTrue($this->allowed('commerce_domains_handover.order', $tab, $seller));
    $this->assertFalse($this->allowed('commerce_domains_handover.order', $tab, $stranger), 'Only for people who may see the order.');
    $this->assertTrue($this->allowed('entity.commerce_domains_handover.collection', [], $viewer));
    $this->assertFalse($this->allowed('entity.commerce_domains_handover.collection', [], $nobody));
    $this->assertTrue($handover->access('view', $viewer));
    $this->assertFalse($handover->access('update', $viewer));

    // Steps are the seller's, and only those open now.
    $step = fn(string $transition) => ['commerce_domains_handover' => $handover->id(), 'transition' => $transition];
    $this->assertTrue($this->allowed('commerce_domains_handover.step', $step('unlock'), $seller));
    $this->assertFalse($this->allowed('commerce_domains_handover.step', $step('unlock'), $viewer));
    $this->assertTrue($this->allowed('commerce_domains_handover.step', $step('send_code'), $seller));
    $this->manager->sendAuthCode($handover);
    $handover = $this->handover($order);
    $this->assertFalse($this->allowed('commerce_domains_handover.step', $step('unlock'), $seller), 'Past unlocking.');
    $this->assertTrue($this->allowed('commerce_domains_handover.step', $step('complete'), $seller));

    // No unlocking without a registrar API.
    $hover = $this->handover($this->pay($this->placeOrder('Hover.com', 'hover')));
    $hover_step = fn(string $transition) => ['commerce_domains_handover' => $hover->id(), 'transition' => $transition];
    $this->assertFalse($this->allowed('commerce_domains_handover.step', $hover_step('unlock'), $seller));
    $this->assertTrue($this->allowed('commerce_domains_handover.step', $hover_step('send_code'), $seller));

    // No tab on orders without domains; starting by hand is the seller's.
    $empty = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'state' => 'draft']);
    $empty->save();
    $this->assertFalse($this->allowed('commerce_domains_handover.order', ['commerce_order' => $empty->id()], $seller));
    $unpaid = $this->placeOrder('Unpaid.com');
    $this->assertTrue($this->allowed('commerce_domains_handover.start', ['commerce_order' => $unpaid->id()], $seller));
    $this->assertFalse($this->allowed('commerce_domains_handover.start', ['commerce_order' => $unpaid->id()], $viewer));
    $this->assertFalse($this->allowed('commerce_domains_handover.start', $tab, $seller), 'Already started.');
  }

  /**
   * Tests that a step happens only on a POST with the form's token.
   */
  public function testCsrf(): void {
    $seller = $this->createUser(['administer commerce_order', 'hand over domains']);
    $this->setCurrentUser($seller);
    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::TRANSFER, registrar: 'Namecheap')));
    $handover = $this->handover($order);
    $form_builder = $this->container->get('form_builder');

    // Showing the form changes nothing.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$handover, 'unlock']);
    $form = $form_builder->buildForm(HandoverStepForm::class, $form_state);
    $this->assertArrayHasKey('form_token', $form, 'The form carries a token for the seller.');
    $this->assertSame([], $this->calls('example.com'));

    // A post without the right token is refused.
    $post = function (string $token) use ($form_builder, $handover): FormStateInterface {
      $form_state = new FormState();
      $form_state->addBuildInfo('args', [$handover, 'unlock']);
      $form_state->setRequestMethod('POST');
      $form_state->setUserInput([
        'form_id' => 'commerce_domains_handover_step',
        'form_token' => $token,
        'confirm' => '1',
        'op' => 'Unlock',
      ]);
      try {
        $form_builder->buildForm(HandoverStepForm::class, $form_state);
      }
      catch (EnforcedResponseException) {
        // The redirect after a step.
      }
      return $form_state;
    };
    $form_state = $post('forged');
    $this->assertNotEmpty($form_state->getErrors());
    $this->assertSame([], $this->calls('example.com'), 'Nothing reached the registrar.');
    $this->assertSame('waiting', $this->handover($order)->getState()->getId());

    // The form's own token takes the step. Forms sign a placeholder made from
    // their ID, which the page fills in. A new request starts without the
    // errors of the last.
    \Closure::bind(fn() => FormState::setAnyErrors(FALSE), NULL, FormState::class)();
    $form_state = $post($this->container->get('csrf_token')->get('form_token_placeholder_' . Crypt::hashBase64('commerce_domains_handover_step')));
    $this->assertSame([], $form_state->getErrors());
    $this->assertTrue($form_state->isExecuted());
    $this->assertSame([['setTransferLock', 'example.com', FALSE]], $this->calls('example.com'));
    $this->assertSame('unlocked', $this->handover($order)->getState()->getId());
  }

  /**
   * Tests the Handover tab and the list of open handovers.
   */
  public function testPages(): void {
    $viewer = $this->createUser(['administer commerce_order', 'view domain handovers']);
    $seller = $this->createUser(['administer commerce_order', 'hand over domains', 'manage domains at registrars']);
    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::PUSH, 'ana@example.com')));
    $controller = HandoverController::create($this->container);

    $this->setCurrentUser($seller);
    $page = $controller->page($order);
    $text = $this->text($page);
    $this->assertStringContainsString('Push to the Dynadot account: ana@example.com', $text);
    $this->assertStringContainsString('Waiting for the seller', $text);
    $this->assertStringContainsString("At Dynadot, push example.com to the buyer's account, ana@example.com.", $text);
    $steps = $page['handover_' . $this->handover($order)->id()]['steps'];
    $this->assertSame(['push', 'complete', 'unlock', 'send_code', 'cancel'], array_values(array_filter(array_keys($steps), fn($key) => $key[0] !== '#')));
    $this->assertSame(['button', 'button--primary'], $steps['push']['#attributes']['class']);
    $this->assertSame('commerce_domains.product_registrar', $page['handover_' . $this->handover($order)->id()]['details']['#rows'][3][1]['data']['#url']->getRouteName());
    $this->assertContains('commerce_domains_handover_list', $page['#cache']['tags']);

    $this->setCurrentUser($viewer);
    $page = $controller->page($order);
    $this->assertSame(['#type'], array_keys($page['handover_' . $this->handover($order)->id()]['steps']), 'Viewers see no steps.');

    // An unpaid order says when its handover starts.
    $this->setCurrentUser($seller);
    $unpaid = $this->placeOrder('Unpaid.com');
    $text = $this->text($controller->page($unpaid));
    $this->assertStringContainsString('The handover of Unpaid.com starts once the order is paid in full.', $text);
    $this->assertStringContainsString('Start the handover', $text);

    // The list shows open handovers only, the longest waiting first.
    $done = $this->pay($this->placeOrder('Done.com', 'dynadot', new Transfer(Transfer::PUSH, 'bo')));
    $this->manager->complete($this->handover($done));
    $list = $this->entityTypeManager->getListBuilder('commerce_domains_handover')->render();
    $text = $this->text($list);
    $this->assertStringContainsString('example.com', $text);
    $this->assertStringNotContainsString('done.com', $text);
    $this->assertStringContainsString('Push to the Dynadot account: ana@example.com', $text);
    $this->manager->complete($this->handover($order));
    $list = $this->entityTypeManager->getListBuilder('commerce_domains_handover')->render();
    $this->assertStringContainsString('No sold domains are waiting to be handed over.', $this->text($list));
  }

}

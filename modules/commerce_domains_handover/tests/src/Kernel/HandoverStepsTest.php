<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\RegistrarSync;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Entity\Handover;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_domains_handover\Form\HandoverStepForm;
use Drupal\commerce_domains_handover\HandoverException;
use Drupal\commerce_domains_handover\HandoverMail;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\commerce_domains_test\Plugin\CommerceDomains\Provider\TestManagedRegistrar;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests each handover step: the registrar, the state, the email and the log.
 */
#[CoversClass(HandoverManager::class)]
#[CoversClass(HandoverStepForm::class)]
#[CoversClass(HandoverMail::class)]
#[CoversClass(Handover::class)]
#[CoversClass(TestManagedRegistrar::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class HandoverStepsTest extends HandoverKernelTestBase {

  /**
   * Submits a step's form.
   */
  protected function step(HandoverInterface $handover, string $transition, array $values = []): FormStateInterface {
    $form_state = (new FormState())->setValues($values);
    $form_state->addBuildInfo('args', [$handover, $transition]);
    $this->container->get('form_builder')->submitForm(HandoverStepForm::class, $form_state);
    return $form_state;
  }

  /**
   * Returns the messages of a type, as strings, and clears them.
   */
  protected function messages(string $type): array {
    return array_map('strval', $this->container->get('messenger')->deleteByType($type));
  }

  /**
   * Returns a paid order's handover.
   */
  protected function paidHandover(string $title, ?string $registrar = 'dynadot', ?Transfer $transfer = NULL): HandoverInterface {
    $order = $this->pay($this->placeOrder($title, $registrar, $transfer ?? new Transfer(Transfer::TRANSFER, registrar: 'Namecheap')));
    return $this->handover($order);
  }

  /**
   * Tests a transfer: unlock, auth code, transferred.
   */
  public function testTransfer(): void {
    $handover = $this->paidHandover('Example.com');
    $order = $handover->getOrder();
    $product = $handover->getProduct();

    $this->step($handover, 'unlock');
    $this->assertSame([['setTransferLock', 'example.com', FALSE]], $this->calls('example.com'));
    $this->assertFalse($this->container->get('state')->get(self::ACCOUNT)['example.com']['locked']);
    $handover = $this->handover($order);
    $this->assertSame('unlocked', $handover->getState()->getId());
    $this->assertSame(['example.com is unlocked at Dynadot.'], $this->messages('status'));
    $this->assertFalse($this->manager->allows($handover, 'unlock'));

    $this->step($handover, 'send_code', ['auth_code' => '']);
    $this->assertSame(['getAuthCode', 'example.com'], $this->calls('example.com')[1]);
    $handover = $this->handover($order);
    $this->assertSame('code_sent', $handover->getState()->getId());
    $this->assertSame(['The auth code for example.com was emailed to buyer@example.com.'], $this->messages('status'));
    $mail = $this->handoverMails(self::BUYER)[1];
    $this->assertSame('The auth code for example.com', (string) $mail['subject']);
    $this->assertTrue(str_contains($this->body($mail), 'code-example.com'), 'The auth code is in the buyer\'s email.');
    $this->assertStringContainsString('to transfer example.com to Namecheap:', $this->body($mail));

    // Again, for a buyer who lost it.
    $this->step($handover, 'send_code', ['auth_code' => '']);
    $this->assertSame('code_sent', $this->handover($order)->getState()->getId());
    $this->assertCount(3, $this->handoverMails(self::BUYER));
    $this->messages('status');

    // Transferred, on the day the seller says.
    $this->assertSame('dynadot', $product->get('domain_registrar')->target_id);
    $this->step($this->handover($order), 'complete', ['transferred' => '2026-10-01']);
    $handover = $this->handover($order);
    $this->assertSame('transferred', $handover->getState()->getId());
    $this->assertSame('2026-10-01', date('Y-m-d', $handover->getTransferredTime()));
    $this->assertSame(['example.com is marked transferred, and no longer expected at Dynadot.'], $this->messages('status'));
    $this->assertSame('example.com is yours', $this->subjects()[3]);
    $this->assertFalse($handover->isOpen());
    foreach (['unlock', 'send_code', 'push', 'complete', 'cancel'] as $transition) {
      $this->assertFalse($this->manager->allows($handover, $transition), "No $transition once transferred.");
    }

    // The product no longer names the seller's registrar; the handover does.
    $product = $this->reloadProduct($product);
    $this->assertTrue($product->get('domain_registrar')->isEmpty());
    $this->assertSame('dynadot', $handover->getRegistrar()->id());
    $this->assertSame([
      'commerce_domains_handover_start',
      'commerce_domains_handover_unlock',
      'commerce_domains_handover_send_code',
      'commerce_domains_handover_send_code',
      'commerce_domains_handover_complete',
    ], array_values(array_filter($this->activity($order), fn(string $id) => str_starts_with($id, 'commerce_domains_handover_'))));
  }

  /**
   * Tests that registrar syncs stop expecting a transferred domain.
   */
  public function testRegistrarSync(): void {
    $handover = $this->paidHandover('Example.com');
    $order = $handover->getOrder();
    $sync = $this->container->get(RegistrarSync::class);
    $provider = Provider::load('dynadot');

    // Sold and paid, it is still the seller's: the sync keeps expecting it.
    $this->assertSame([], $sync->plan($provider)->missing);
    $account = $this->container->get('state')->get(self::ACCOUNT);
    unset($account['example.com']);
    $this->container->get('state')->set(self::ACCOUNT, $account);
    $this->assertSame(['example.com'], $sync->plan($provider)->missing, 'Gone before the handover closes: reported.');

    $this->manager->complete($handover);
    $this->assertSame([], $sync->plan($provider)->missing, 'Transferred: no longer expected.');
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), $this->handover($order)->getTransferredTime());
  }

  /**
   * Tests a push, and warnings about a domain still in the account.
   */
  public function testPush(): void {
    $handover = $this->paidHandover('Example.com', 'dynadot', new Transfer(Transfer::PUSH, 'ana@example.com'));
    $order = $handover->getOrder();

    $this->step($handover, 'push');
    $this->assertSame([], $this->calls('example.com'), 'A push is marked, not made through the API.');
    $handover = $this->handover($order);
    $this->assertSame('pushed', $handover->getState()->getId());
    $mail = $this->handoverMails(self::BUYER)[1];
    $this->assertSame('example.com is on its way to your account', (string) $mail['subject']);
    $this->assertStringContainsString('We pushed example.com to your Dynadot account, ana@example.com.', $this->body($mail));
    $this->assertFalse($this->manager->allows($handover, 'send_code'));

    // The account still holds it: the seller is warned before marking it.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$handover, 'complete']);
    $form = $this->container->get('form_builder')->buildForm(HandoverStepForm::class, $form_state);
    $this->assertSame('Mark example.com transferred to the buyer?', (string) $form['#title']);
    $this->assertStringContainsString('The Dynadot account still holds example.com.', implode(' ', $this->messages('warning')));

    $this->manager->complete($handover);
    $this->assertSame('transferred', $this->handover($order)->getState()->getId());
    $this->assertContains('commerce_domains_handover_push', $this->activity($order));
  }

  /**
   * Tests refusals, a registrar that emails the code, and one without an API.
   */
  public function testRefusals(): void {
    // The registrar refuses: nothing changes.
    $refused = $this->paidHandover('Refuse.com');
    $this->step($refused, 'unlock');
    $this->assertSame(['Dynadot refused: Refused.'], $this->messages('error'));
    $this->assertSame('waiting', $this->handover($refused->getOrder())->getState()->getId());
    $this->step($refused, 'send_code', ['auth_code' => '']);
    $this->assertSame(['Dynadot refused: Refused.'], $this->messages('error'));
    $this->assertSame('waiting', $this->handover($refused->getOrder())->getState()->getId());
    $this->assertCount(1, $this->handoverMails(), 'Only the first email.');

    // The registrar emails the code to the seller, who enters it.
    $mailed = $this->paidHandover('Mailed.com');
    $form_state = $this->step($mailed, 'send_code', ['auth_code' => '']);
    $this->assertSame(["Dynadot emailed the auth code for mailed.com to the domain's administrative contact. Enter it here to send it to the buyer."], $this->messages('error'));
    // Programmed submissions don't redirect; read what the form set.
    $redirect = $form_state->setProgrammed(FALSE)->getRedirect();
    $this->assertSame('commerce_domains_handover.step', $redirect->getRouteName(), 'Back to the form, to enter the code.');
    $this->assertSame('waiting', $this->handover($mailed->getOrder())->getState()->getId());
    $this->step($mailed, 'send_code', ['auth_code' => ' entered-code ']);
    $this->assertSame('code_sent', $this->handover($mailed->getOrder())->getState()->getId());
    $mails = $this->handoverMails();
    $this->assertTrue(str_contains($this->body(end($mails)), 'entered-code'), 'The entered code is in the buyer\'s email.');

    // Without an API: no unlock, and the code must be entered.
    $hover = $this->paidHandover('Hover.com', 'hover');
    $this->assertFalse($this->manager->allows($hover, 'unlock'));
    $this->assertNull($this->manager->management($hover));
    try {
      $this->manager->sendAuthCode($hover);
      $this->fail('No code to send.');
    }
    catch (HandoverException $e) {
      $this->assertSame('Enter the auth code for hover.com: there is no registrar API to get it from.', (string) $e->reason);
      $this->assertSame('Enter the auth code for hover.com: there is no registrar API to get it from.', $e->getMessage());
    }
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$hover, 'send_code']);
    $form = $this->container->get('form_builder')->buildForm(HandoverStepForm::class, $form_state);
    $this->assertTrue($form['auth_code']['#required']);
    $this->assertSame('password', $form['auth_code']['#type']);
    $this->manager->sendAuthCode($hover, 'hover-code');
    $this->assertSame('code_sent', $this->handover($hover->getOrder())->getState()->getId());

    // A disabled registrar has no API either.
    Provider::load('dynadot')->disable()->save();
    $this->entityTypeManager->getStorage('commerce_product')->resetCache();
    $this->assertNull($this->manager->management($this->handover($refused->getOrder())));

    // A step past its state is refused.
    $this->expectException(HandoverException::class);
    $this->manager->unlock($this->handover($mailed->getOrder()));
  }

  /**
   * Tests canceling, and a date in the future.
   */
  public function testCancelAndDates(): void {
    $handover = $this->paidHandover('Example.com');
    $form_state = $this->step($handover, 'complete', ['transferred' => '2999-01-01']);
    $this->assertSame('The transfer date cannot be in the future.', (string) $form_state->getErrors()['transferred']);
    $form_state = $this->step($handover, 'complete', ['transferred' => 'soon']);
    $this->assertSame('Enter the date the domain was transferred.', (string) $form_state->getErrors()['transferred']);
    $this->assertSame('waiting', $this->handover($handover->getOrder())->getState()->getId());

    $this->step($handover, 'cancel');
    $canceled = $this->handover($handover->getOrder());
    $this->assertSame('canceled', $canceled->getState()->getId());
    $this->assertSame(['The handover of example.com is canceled.'], $this->messages('status'));
    $this->assertSame('dynadot', $this->reloadProduct($canceled->getProduct())->get('domain_registrar')->target_id, 'The domain stays the seller\'s.');
    $this->assertCount(1, $this->handoverMails(), 'Canceling emails nobody.');
    $this->assertContains('commerce_domains_handover_cancel', $this->activity($canceled->getOrder()));
    $this->expectException(\InvalidArgumentException::class);
    $canceled->getState()->applyTransitionById('complete');
  }

  /**
   * Tests that uninstalling leaves the orders' activity readable.
   */
  public function testUninstall(): void {
    $this->installSchema('user', ['users_data']);
    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::PUSH, 'ana')));
    $this->manager->push($this->handover($order));
    $this->assertContains('commerce_domains_handover_push', $this->activity($order));

    // Handovers go first, as Drupal asks on the uninstall page.
    $storage = $this->entityTypeManager->getStorage('commerce_domains_handover');
    $storage->delete($storage->loadMultiple());
    $this->container->get('module_installer')->uninstall(['commerce_domains_handover']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $activity = $this->activity($order);
    $this->assertNotEmpty($activity, "The order's own entries stay.");
    $this->assertSame([], array_filter($activity, fn(string $id) => str_contains($id, 'handover')));
    /** @var \Drupal\commerce_log\LogStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_log');
    $build = $this->entityTypeManager->getViewBuilder('commerce_log')->viewMultiple($storage->loadMultipleByEntity($order));
    $this->assertNotEmpty((string) $this->container->get('renderer')->renderInIsolation($build));
  }

  /**
   * Tests handovers on a site without Commerce Log.
   */
  public function testWithoutLog(): void {
    $this->installSchema('user', ['users_data']);
    $this->container->get('module_installer')->uninstall(['commerce_log']);
    $this->manager = $this->container->get(HandoverManager::class);
    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::PUSH, 'ana')));
    $this->assertTrue($this->manager->push($this->handover($order)));
    $this->assertTrue($this->manager->complete($this->handover($order)));
    $this->assertSame('transferred', $this->handover($order)->getState()->getId());
    $this->assertSame([
      'What happens next with example.com',
      'example.com is on its way to your account',
      'example.com is yours',
    ], $this->subjects());
  }

}

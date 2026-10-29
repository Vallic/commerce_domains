<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_handover\Kernel;

use Drupal\commerce\MailHandlerInterface;
use Drupal\commerce_domains_checkout\Transfer;
use Drupal\commerce_domains_handover\Controller\HandoverController;
use Drupal\commerce_domains_handover\Entity\HandoverInterface;
use Drupal\commerce_domains_handover\Form\HandoverStepForm;
use Drupal\commerce_domains_handover\HandoverMail;
use Drupal\commerce_domains_handover\HandoverManager;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an auth code reaches the buyer's email and nothing else.
 *
 * Not the seller's screen, messages, logs, the order's activity, order data,
 * the handover, any other email, or a stack trace. The assertions never
 * print a code.
 */
#[CoversClass(HandoverManager::class)]
#[CoversClass(HandoverMail::class)]
#[CoversClass(HandoverStepForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class AuthCodeSecrecyTest extends HandoverKernelTestBase {

  // The test registrar's code for example.com.
  protected const CODE = 'code-example.com';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system_mail_failure_test'];

  /**
   * Returns a paid order's handover, after its first email.
   */
  protected function paidHandover(): HandoverInterface {
    $order = $this->pay($this->placeOrder('Example.com', 'dynadot', new Transfer(Transfer::TRANSFER, registrar: 'Namecheap')));
    return $this->handover($order);
  }

  /**
   * Submits the auth code step as the seller.
   */
  protected function sendCode(HandoverInterface $handover, string $entered = ''): void {
    $form_state = (new FormState())->setValues(['auth_code' => $entered]);
    $form_state->addBuildInfo('args', [$handover, 'send_code']);
    $this->container->get('form_builder')->submitForm(HandoverStepForm::class, $form_state);
  }

  /**
   * Fails unless exactly the buyer's auth code emails carry the code.
   */
  protected function assertOnlyInBuyerEmail(string $code, int $count): void {
    $carrying = array_filter($this->getMails(), fn(array $mail) => str_contains(serialize($mail), $code));
    $this->assertCount($count, $carrying, 'The code is only in the auth code emails.');
    foreach ($carrying as $mail) {
      $this->assertSame('commerce_commerce_domains_handover_auth_code', $mail['id']);
      $this->assertSame(self::BUYER, $mail['to']);
      $this->assertArrayNotHasKey('Bcc', $mail['headers'], 'Copied to nobody.');
      $this->assertArrayNotHasKey('Cc', $mail['headers'], 'Copied to nobody.');
      $this->assertTrue(str_contains($this->body($mail), $code), "It is in the email's text.");
    }
  }

  /**
   * Fails when the code is in the database, outside the caught emails.
   *
   * Covers the log, the order's activity and data, the handover, State and
   * the form cache.
   */
  protected function assertNotStored(string $code): void {
    $database = $this->container->get('database');
    $tables = $database->schema()->findTables('%');
    $this->assertContains('watchdog', $tables);
    $this->assertContains('commerce_log', $tables);
    $holding = [];
    foreach ($tables as $table) {
      foreach ($database->select($table, 't')->fields('t')->execute()->fetchAll(FetchAs::Associative) as $row) {
        // Where the test mail collector keeps the emails it caught.
        if ($table === 'key_value' && $row['collection'] === 'state' && $row['name'] === 'system.test_mail_collector') {
          continue;
        }
        if (str_contains(serialize($row), $code)) {
          $holding[$table] = $table;
        }
      }
    }
    $this->assertSame([], array_values($holding), 'No table holds the code.');
  }

  /**
   * Fails when a message shows the code.
   */
  protected function assertNotInMessages(string $code): void {
    $messages = array_merge(...array_values($this->container->get('messenger')->all()));
    $this->assertNotEmpty($messages);
    $this->assertFalse(str_contains(implode(' ', array_map('strval', $messages)), $code), 'No message shows the code.');
  }

  /**
   * Tests a code from the registrar's API.
   */
  public function testCodeFromRegistrar(): void {
    $seller = $this->createUser(['administer commerce_order', 'hand over domains', 'manage domains at registrars']);
    $this->setCurrentUser($seller);
    $handover = $this->paidHandover();

    // The page asks first, and fetches nothing.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$handover, 'send_code']);
    $form = $this->container->get('form_builder')->buildForm(HandoverStepForm::class, $form_state);
    $this->assertSame('password', $form['auth_code']['#type']);
    $this->assertFalse($form['auth_code']['#required']);
    $this->assertSame([], $this->calls('example.com'));

    $this->sendCode($handover);
    $this->assertSame([['getAuthCode', 'example.com']], $this->calls('example.com'));
    $handover = $this->handover($handover->getOrder());
    $this->assertSame('code_sent', $handover->getState()->getId());
    $this->assertOnlyInBuyerEmail(self::CODE, 1);
    $this->assertNotStored(self::CODE);
    $this->assertNotInMessages(self::CODE);
    $this->assertContains('commerce_domains_handover_send_code', $this->activity($handover->getOrder()));
    $this->assertContains('mail_commerce_domains_handover_auth_code', $this->activity($handover->getOrder()));
    $notices = $this->container->get('database')->select('watchdog', 'w')->fields('w', ['message'])->condition('type', 'commerce_domains_handover')->execute()->fetchCol();
    $this->assertContains('@domain: auth code emailed to the buyer of order @order.', $notices, 'Sending is logged, without the code.');

    // The seller's Handover tab doesn't show it either.
    $page = HandoverController::create($this->container)->page($handover->getOrder());
    $html = (string) $this->container->get('renderer')->renderInIsolation($page);
    $this->assertStringContainsString('Auth code sent', $html);
    $this->assertFalse(str_contains($html, self::CODE), 'The Handover tab never shows the code.');
  }

  /**
   * Tests a code the seller enters, and an email that fails.
   */
  public function testEnteredCode(): void {
    $code = 'Ent3red-Secret';
    $handover = $this->paidHandover();

    // The email fails: the buyer has no code, so the step isn't taken.
    $this->config('system.mail')->set('interface.commerce_commerce_domains_handover_auth_code', 'test_php_mail_failure')->save();
    $this->sendCode($handover, $code);
    $this->assertSame('waiting', $this->handover($handover->getOrder())->getState()->getId());
    $errors = array_map('strval', $this->container->get('messenger')->messagesByType('error'));
    $this->assertContains('The email to buyer@example.com could not be sent, so the buyer has no auth code. Try again.', $errors);
    $this->assertSame([], $this->calls('example.com'), 'An entered code needs no registrar.');
    $this->assertNotStored($code);
    $this->assertNotInMessages($code);
    $this->assertContains('mail_commerce_domains_handover_auth_code_failure', $this->activity($handover->getOrder()));

    $this->config('system.mail')->clear('interface.commerce_commerce_domains_handover_auth_code')->save();
    $this->sendCode($handover, $code);
    $this->assertSame('code_sent', $this->handover($handover->getOrder())->getState()->getId());
    $this->assertOnlyInBuyerEmail($code, 1);
    $this->assertNotStored($code);
  }

  /**
   * Tests that a stack trace from sending leaves the code out.
   */
  public function testStackTrace(): void {
    $code = 'trace-code-1';
    $handover = $this->paidHandover();
    // Mail that breaks with an exception, which Drupal logs with its trace.
    $broken = new class() implements MailHandlerInterface {

      /**
       * {@inheritdoc}
       */
      public function sendMail($to, $subject, array $body, array $params = []) {
        throw new \RuntimeException('Mail is down.');
      }

    };
    $manager = new HandoverManager($this->entityTypeManager, new HandoverMail($broken), $this->container->get('datetime.time'), $this->container->get('logger.channel.commerce_domains_handover'));
    $ignore_args = ini_set('zend.exception_ignore_args', '0');
    try {
      $manager->sendAuthCode($handover, $code);
      $this->fail('The mail handler throws.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Mail is down.', $e->getMessage());
      $this->assertFalse(str_contains($e->getTraceAsString(), $code), 'The trace leaves the code out.');
      foreach ($e->getTrace() as $frame) {
        foreach ($frame['args'] ?? [] as $argument) {
          $this->assertFalse(is_string($argument) && str_contains($argument, $code), 'No call in the trace takes the code as a plain argument.');
        }
      }
    }
    finally {
      ini_set('zend.exception_ignore_args', (string) $ignore_args);
    }
    $this->assertSame('waiting', $this->handover($handover->getOrder())->getState()->getId());
  }

}

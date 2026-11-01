<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains_owner\Drush\Commands\RenewalCostCommands;
use Drupal\commerce_domains_owner\Form\SettingsForm;
use Drupal\commerce_domains_owner\RenewalCostDefaults;
use Drupal\Core\Form\FormState;
use Drupal\Tests\commerce_domains\Kernel\DrushCommandTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests filling the default renewal costs from Porkbun's price list.
 */
#[CoversClass(RenewalCostDefaults::class)]
#[CoversClass(PorkbunClient::class)]
#[CoversClass(SettingsForm::class)]
#[CoversClass(RenewalCostCommands::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RenewalCostDefaultsTest extends OwnerKernelTestBase {

  use DrushCommandTrait;

  /**
   * The requests sent.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * Answers the next requests with Porkbun's price list.
   */
  protected function respond(int $times = 1): void {
    $body = json_encode([
      'status' => 'SUCCESS',
      'pricing' => [
        'com' => ['registration' => '11.81', 'renewal' => '11.81', 'transfer' => '11.81'],
        'co.uk' => ['registration' => '5.66', 'renewal' => '5.66', 'transfer' => '0.00'],
      ],
      'unsupported' => ['example'],
    ]);
    $stack = HandlerStack::create(new MockHandler(array_fill(0, $times, new Response(200, [], $body))));
    $this->history = [];
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->container->set(RenewalCostDefaults::class, NULL);
  }

  /**
   * Tests filling in, keeping what is set, and replacing it.
   */
  public function testFill(): void {
    $this->createDomain('One.com');
    $this->createDomain('Two.com');
    $this->createDomain('Shop.co.uk');
    $this->createDomain('Odd.example');
    $this->config('commerce_domains_owner.settings')->set('renewal_costs', [
      ['tld' => 'com', 'number' => '9', 'currency_code' => 'USD'],
      ['tld' => 'io', 'number' => '40', 'currency_code' => 'USD'],
    ])->save();

    $this->respond();
    $defaults = $this->container->get(RenewalCostDefaults::class);
    $this->assertSame(['co.uk', 'com', 'example'], $defaults->catalogExtensions());
    $fill = $defaults->fromPorkbun();
    $request = $this->history[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('https://api.porkbun.com/api/json/v3/pricing/get', (string) $request->getUri()->withQuery(''));
    parse_str($request->getUri()->getQuery(), $query);
    $this->assertSame('co.uk,com,example', $query['tlds']);
    $this->assertFalse($request->hasHeader('X-API-Key'), 'The price list is public.');
    $this->assertSame(['co.uk' => '5.66'], $fill['added']);
    $this->assertSame([], $fill['replaced'], 'Costs already set stay.');
    $this->assertSame(['example'], $fill['unpriced']);
    $this->assertSame([
      ['tld' => 'co.uk', 'number' => '5.66', 'currency_code' => 'USD'],
      ['tld' => 'com', 'number' => '9', 'currency_code' => 'USD'],
      ['tld' => 'io', 'number' => '40', 'currency_code' => 'USD'],
    ], $fill['costs']);

    $this->respond();
    $fill = $this->container->get(RenewalCostDefaults::class)->fromPorkbun(TRUE);
    $this->assertSame(['com' => '11.81'], $fill['replaced']);
    $this->assertSame('11.81', $fill['costs'][1]['number']);
  }

  /**
   * Tests the settings form's button and the Drush command.
   */
  public function testFormAndDrush(): void {
    $this->createDomain('Shop.co.uk');
    $this->respond();
    $form_state = (new FormState())->setValues(['op' => "Fill in from Porkbun's prices"]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([['tld' => 'co.uk', 'number' => '5.66', 'currency_code' => 'USD']], $this->config('commerce_domains_owner.settings')->get('renewal_costs'));

    $this->requireDrush();
    $this->createDomain('Shop.com');
    $this->respond(2);
    $command = new RenewalCostCommands($this->container->get(RenewalCostDefaults::class));
    $this->prepareCommand($command);
    $command->fill(['replace' => FALSE, 'dry-run' => TRUE]);
    $this->assertMatchesRegularExpression('/com\s+USD 11\.81\s+added/', $this->output->fetch());
    $this->assertCount(1, $this->config('commerce_domains_owner.settings')->get('renewal_costs'), 'A dry run saves nothing.');
    $command->fill(['replace' => FALSE, 'dry-run' => FALSE]);
    $this->assertCount(2, $this->config('commerce_domains_owner.settings')->get('renewal_costs'));
  }

}

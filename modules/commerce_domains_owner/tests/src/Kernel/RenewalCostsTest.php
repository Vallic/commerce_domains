<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_owner\Kernel;

use Drupal\commerce_domains\Clients\PorkbunClient;
use Drupal\commerce_domains\ProviderException;
use Drupal\commerce_domains_owner\Costs;
use Drupal\commerce_domains_owner\Drush\Commands\RenewalCostCommands;
use Drupal\commerce_domains_owner\Entity\RenewalCost;
use Drupal\commerce_domains_owner\Form\RenewalCostImportForm;
use Drupal\commerce_domains_owner\RenewalCostImporter;
use Drupal\commerce_domains_owner\RenewalCostListBuilder;
use Drupal\commerce_domains_owner\RenewalCosts;
use Drupal\commerce_price\Price;
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
 * Tests the table of renewal costs and filling it.
 */
#[CoversClass(RenewalCost::class)]
#[CoversClass(RenewalCosts::class)]
#[CoversClass(RenewalCostImporter::class)]
#[CoversClass(RenewalCostImportForm::class)]
#[CoversClass(RenewalCostListBuilder::class)]
#[CoversClass(RenewalCostCommands::class)]
#[CoversClass(PorkbunClient::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RenewalCostsTest extends OwnerKernelTestBase {

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
    ]);
    $stack = HandlerStack::create(new MockHandler(array_fill(0, $times, new Response(200, [], $body))));
    $this->history = [];
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->container->set(RenewalCostImporter::class, NULL);
  }

  /**
   * Returns the table as lines: extension, registrar, amount.
   *
   * @return string[]
   *   The rows, sorted.
   */
  protected function table(): array {
    $lines = [];
    foreach ($this->container->get('entity_type.manager')->getStorage('commerce_domains_renewal_cost')->loadMultiple() as $row) {
      /** @var \Drupal\commerce_domains_owner\Entity\RenewalCost $row */
      $lines[] = trim($row->getTld() . ' ' . $row->getProviderId()) . ' ' . $row->getCost();
    }
    sort($lines);
    return $lines;
  }

  /**
   * Tests looking costs up: a registrar's first, the longest extension first.
   */
  public function testLookup(): void {
    $this->createProvider('dynadot');
    $this->setRenewalCosts([
      ['com', '12'],
      ['uk', '5'],
      ['co.uk', '7'],
      ['com', '10.88', 'USD', 'dynadot'],
      ['uk', '4', 'USD', 'dynadot'],
    ]);
    $costs = $this->container->get(RenewalCosts::class);
    $this->assertEquals(new Price('12', 'USD'), $costs->forDomain('example.com'));
    $this->assertEquals(new Price('10.88', 'USD'), $costs->forDomain('EXAMPLE.com', 'dynadot'));
    $this->assertEquals(new Price('12', 'USD'), $costs->forDomain('example.com', 'other'));
    $this->assertEquals(new Price('7', 'USD'), $costs->forDomain('example.co.uk'));
    $this->assertEquals(new Price('4', 'USD'), $costs->forDomain('example.co.uk', 'dynadot'), "The registrar's shorter extension before the general longer one.");
    $this->assertNull($costs->forDomain('example.io'));
    $this->assertNull($costs->forDomain('notcom'), 'Only whole extensions.');

    // Changes count at once, and one row is kept for each pair.
    $costs->set('.COM', 'dynadot', new Price('9', 'USD'));
    $this->assertEquals(new Price('9', 'USD'), $costs->forDomain('example.com', 'dynadot'));
    $this->assertCount(5, $this->table());
    $costs->find('com', 'dynadot')->delete();
    $this->assertEquals(new Price('12', 'USD'), $costs->forDomain('example.com', 'dynadot'));
  }

  /**
   * Tests reading a file: what it adds, replaces and can't.
   */
  public function testFile(): void {
    $this->createProvider('dynadot');
    $this->setRenewalCosts([['com', '12'], ['net', '13'], ['io', '40', 'USD', 'dynadot']]);
    $importer = $this->container->get(RenewalCostImporter::class);
    $rows = RenewalCostImporter::parse("sep=,\nTLD,Renewal,Currency,Registrar\n.COM,\"10,50\",USD,\nnet,13,USD,\nio,\"39 USD\",,Dynadot\nxyz,2,XXX,\norg,ten,USD,\ninfo,3,,\nbiz,3,USD,nobody\nnet,14,USD,\nco.uk,7,USD,dynadot\n");
    $plan = $importer->planRows($rows);
    $this->assertSame(['replaced', 'same', 'replaced', 'problem', 'problem', 'problem', 'problem', 'problem', 'new'], array_column($plan, 'status'));
    $this->assertSame(['new' => 1, 'replaced' => 2, 'same' => 1, 'kept' => 0, 'problem' => 5], RenewalCostImporter::summary($plan));
    $this->assertStringContainsString('Already on line 4', $plan[10]['problem']);
    $this->assertSame(3, $importer->apply($plan));
    $this->assertSame([
      'co.uk dynadot 7 USD',
      'com 10.5 USD',
      'io dynadot 39 USD',
      'net 13 USD',
    ], $this->table());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('No extension column');
    RenewalCostImporter::parse("domain,price\nexample.com,1\n");
  }

  /**
   * Tests Porkbun's public list: the gaps, for the catalog's extensions.
   */
  public function testPorkbunList(): void {
    $this->createDomain('One.com');
    $this->createDomain('Shop.co.uk');
    $this->createDomain('Odd.example');
    $this->setRenewalCosts([['com', '9'], ['io', '40']]);
    $this->assertSame(['co.uk', 'example'], $this->container->get(RenewalCosts::class)->missing());

    $this->respond();
    $list = $this->container->get(RenewalCostImporter::class)->planList(RenewalCostImporter::PORKBUN_LIST);
    $request = $this->history[0]['request'];
    $this->assertSame('https://api.porkbun.com/api/json/v3/pricing/get', (string) $request->getUri()->withQuery(''));
    parse_str($request->getUri()->getQuery(), $query);
    $this->assertSame('co.uk,com,example', $query['tlds']);
    $this->assertFalse($request->hasHeader('X-API-Key'), 'The price list is public.');
    $this->assertSame(['new', 'kept'], array_column($list['plan'], 'status'));
    $this->assertSame(['example'], $list['unpriced']);

    $this->respond();
    $list = $this->container->get(RenewalCostImporter::class)->planList(RenewalCostImporter::PORKBUN_LIST, TRUE);
    $this->assertSame(['new', 'replaced'], array_column($list['plan'], 'status'));
    $this->container->get(RenewalCostImporter::class)->apply($list['plan']);
    $this->assertEquals(new Price('11.81', 'USD'), $this->container->get(RenewalCosts::class)->forDomain('a.com'));
    $this->assertSame(['example'], $this->container->get(RenewalCosts::class)->missing());
  }

  /**
   * Tests a registrar's own list: its rows, replaced, and only its capability.
   */
  public function testRegistrarList(): void {
    $this->createProvider('managed', 'test_managed_registrar');
    $this->createProvider('plain');
    $this->createDomain('One.com');
    $this->createDomain('Two.de');
    $this->createDomain('Odd.example');
    $this->setRenewalCosts([['com', '12'], ['com', '9', 'USD', 'managed']]);
    $importer = $this->container->get(RenewalCostImporter::class);
    $this->assertSame(['managed', RenewalCostImporter::PORKBUN_LIST], array_keys($importer->sources()));

    $list = $importer->planList('managed');
    $this->assertSame(['com' => 'replaced', 'de' => 'new'], array_column($list['plan'], 'status', 'tld'));
    $this->assertSame(['example'], $list['unpriced']);
    $this->assertSame(2, $importer->apply($list['plan']));
    $costs = $this->container->get(RenewalCosts::class);
    $this->assertEquals(new Price('10.88', 'USD'), $costs->forDomain('a.com', 'managed'));
    $this->assertEquals(new Price('12', 'USD'), $costs->forDomain('a.com'), 'The general cost stays.');

    $this->expectException(ProviderException::class);
    $importer->planList('plain');
  }

  /**
   * Tests the import form from file to table, and the list.
   */
  public function testForms(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('router.builder')->rebuild();
    $builder = $this->container->get('form_builder');
    $form_state = (new FormState())->setValues([
      'source' => 'file',
      'text' => "extension,renewal,currency\ncom,10.50,USD\nnet,nope,USD\n",
      'op' => 'Preview',
    ]);
    $builder->submitForm(RenewalCostImportForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([2, 3], array_keys($form_state->get('plan')));
    $this->assertSame([], $this->table(), 'A preview saves nothing.');
    $this->container->get(RenewalCostImporter::class)->apply($form_state->get('plan'));
    $this->assertEquals(new Price('10.50', 'USD'), $this->container->get(RenewalCosts::class)->forDomain('a.com'));

    $this->createDomain('Shop.org');
    $listing = $this->container->get('entity_type.manager')->getListBuilder('commerce_domains_renewal_cost')->render();
    $this->assertStringContainsString('org', (string) $listing['missing']['#markup']);
    $this->assertStringContainsString('$10.50', (string) $listing['table']['#rows'][array_key_first($listing['table']['#rows'])]['cost']);

    // Two rows for an extension and registrar aren't allowed by hand.
    $cost = RenewalCost::create(['tld' => '', 'cost' => new Price('1', 'USD')]);
    $form_state = (new FormState())->setValues([
      'tld' => [['value' => '.COM']],
      'provider' => '_none',
      'cost' => [['number' => '1', 'currency_code' => 'USD']],
      'op' => 'Save',
    ]);
    $this->container->get('entity.form_builder')->getForm($cost);
    $builder->submitForm($this->container->get('entity_type.manager')->getFormObject('commerce_domains_renewal_cost', 'add')->setEntity($cost), $form_state);
    $this->assertArrayHasKey('tld', $form_state->getErrors());
    $this->assertCount(1, $this->table());
  }

  /**
   * Tests the Drush command.
   */
  public function testDrush(): void {
    $this->requireDrush();
    $this->createDomain('Shop.co.uk');
    $this->respond(2);
    $command = new RenewalCostCommands($this->container->get(RenewalCostImporter::class));
    $this->prepareCommand($command);
    $options = ['from' => RenewalCostImporter::PORKBUN_LIST, 'replace' => FALSE, 'dry-run' => TRUE];
    $command->fill(NULL, $options);
    $this->assertMatchesRegularExpression('/co\.uk\s+any\s+USD 5\.66\s+new/', $this->output->fetch());
    $this->assertCount(0, $this->table(), 'A dry run saves nothing.');
    $command->fill(NULL, ['dry-run' => FALSE] + $options);
    $this->assertCount(1, $this->table());

    $file = tempnam(sys_get_temp_dir(), 'costs');
    file_put_contents($file, "extension,renewal\nco.uk,6.10 USD\n");
    $command->fill($file, ['dry-run' => FALSE] + $options);
    unlink($file);
    $this->assertEquals(new Price('6.10', 'USD'), $this->container->get(RenewalCosts::class)->forDomain('a.co.uk'));
  }

  /**
   * Tests moving the costs out of the settings.
   */
  public function testUpdate(): void {
    $this->createProvider('dynadot');
    // The settings as they were, written past the schema that no longer has
    // them.
    $name = 'commerce_domains_owner.settings';
    $this->container->get('config.storage')->write($name, [
      'renewal_costs' => [
        ['tld' => 'com', 'number' => '12', 'currency_code' => 'USD'],
      ],
      'provider_renewal_costs' => [
        ['provider' => 'dynadot', 'tld' => 'com', 'number' => '10.88', 'currency_code' => 'USD'],
      ],
    ] + $this->config($name)->getRawData());
    $this->container->get('config.factory')->reset($name);
    $this->assertSame('2 renewal costs moved.', commerce_domains_owner_update_11008());
    $settings = $this->config('commerce_domains_owner.settings');
    $this->assertNull($settings->get('renewal_costs'));
    $this->assertNull($settings->get('provider_renewal_costs'));
    $costs = $this->container->get(Costs::class);
    $this->assertEquals(new Price('12', 'USD'), $costs->defaultRenewalCost('a.com'));
    $this->assertEquals(new Price('10.88', 'USD'), $costs->defaultRenewalCost('a.com', 'dynadot'));
  }

}

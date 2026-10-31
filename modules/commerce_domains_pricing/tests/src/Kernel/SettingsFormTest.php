<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_pricing\Kernel;

use Drupal\commerce_domains_pricing\Form\SettingsForm;
use Drupal\commerce_domains_pricing\PriceRules;
use Drupal\commerce_pricelist\Entity\PriceList;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the domain pricing settings page.
 */
#[CoversClass(SettingsForm::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends DomainsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_pricelist', 'commerce_domains_pricing'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_pricelist');
    $this->installConfig(['commerce_domains_pricing']);
  }

  /**
   * Tests that the page takes its own permission.
   */
  public function testAccess(): void {
    $url = Url::fromRoute('commerce_domains_pricing.settings');
    $this->assertSame('/admin/commerce/domains/settings/pricing', $url->toString());
    // User 1 may do anything; the next ones have their permissions only.
    $this->createUser();
    $this->assertFalse($url->access($this->createUser([
      'administer commerce_product',
      'administer commerce_domains providers',
    ])));
    $this->assertTrue($url->access($this->createUser(['administer commerce_domains pricing'])));
  }

  /**
   * Tests saving rules, rounding steps and where currency prices go.
   */
  public function testSave(): void {
    $list = PriceList::create([
      'type' => 'commerce_product_variation',
      'name' => 'Marketplace prices',
      'stores' => [$this->store],
      'status' => FALSE,
    ]);
    $list->save();

    // The form starts with an empty row for each table.
    $this->submit([
      'rules' => [
        0 => $this->ruleRow([
          'extensions' => '.COM, co.uk  münchen',
          'length' => ['min' => '', 'max' => '5'],
          'price' => ['number' => '2999', 'currency_code' => 'USD'],
        ]),
      ],
      'whole_units' => 1,
      'steps' => [
        0 => ['up_to' => '', 'method' => 'nearest', 'multiple' => '100', 'subtract' => '', 'max' => ''],
      ],
      'currencies' => ['EUR' => 'EUR'],
      'price_list' => $list->uuid(),
      'field_prefix' => 'field_price_',
    ]);
    $config = $this->config(PriceRules::CONFIG);
    $this->assertSame([
      [
        'extensions' => ['com', 'co.uk', 'xn--mnchen-3ya'],
        'min_length' => NULL,
        'max_length' => 5,
        'min_words' => NULL,
        'max_words' => NULL,
        'atom_premium' => NULL,
        'price' => ['number' => '2999', 'currency_code' => 'USD'],
      ],
    ], $config->get('rules'));
    $this->assertTrue($config->get('rounding.whole_units'));
    $this->assertSame([['up_to' => NULL, 'method' => 'nearest', 'multiple' => 100.0, 'subtract' => 0.0, 'max' => NULL]], $config->get('rounding.steps'));
    $this->assertSame(['EUR'], $config->get('currencies'));
    $this->assertSame($list->uuid(), $config->get('price_list'));
    $this->assertSame('field_price_', $config->get('field_prefix'));

    // A second rule, moved above the first, and a step with a limit, which
    // goes before the one without.
    $this->submit([
      'rules' => [
        0 => $this->ruleRow([
          'extensions' => 'com, co.uk, münchen',
          'length' => ['min' => '', 'max' => '5'],
          'price' => ['number' => '2999', 'currency_code' => 'USD'],
          'weight' => 1,
        ]),
        1 => $this->ruleRow([
          'atom_premium' => '1',
          'words' => ['min' => '1', 'max' => '2'],
          'price' => ['number' => '5000', 'currency_code' => 'EUR'],
          'weight' => 0,
        ]),
      ],
      'steps' => [
        0 => ['up_to' => '', 'method' => 'nearest', 'multiple' => '100', 'subtract' => '0', 'max' => ''],
        1 => ['up_to' => '10000', 'method' => 'up', 'multiple' => '100', 'subtract' => '1', 'max' => '9999'],
      ],
      'currencies' => [],
      'price_list' => '',
    ]);
    $config = $this->config(PriceRules::CONFIG);
    $rules = $this->container->get(PriceRules::class)->rules();
    $this->assertCount(2, $rules);
    $this->assertTrue($rules[0]->atomPremium);
    $this->assertSame([1, 2], [$rules[0]->minWords, $rules[0]->maxWords]);
    $this->assertSame('EUR', $rules[0]->price->getCurrencyCode());
    $this->assertSame(['com', 'co.uk', 'xn--mnchen-3ya'], $rules[1]->extensions);
    $this->assertSame([10000.0, NULL], array_column($config->get('rounding.steps'), 'up_to'));
    $this->assertSame([], $config->get('currencies'));
    $this->assertNull($config->get('price_list'));

    // Removing the first rule and the step without a limit.
    $this->submit([
      'rules' => [
        0 => $this->ruleRow([
          'atom_premium' => '1',
          'price' => ['number' => '5000', 'currency_code' => 'EUR'],
          'remove' => 1,
        ]),
        1 => $this->ruleRow(['extensions' => 'com', 'price' => ['number' => '2999', 'currency_code' => 'USD']]),
      ],
      'steps' => [
        0 => ['up_to' => '10000', 'method' => 'up', 'multiple' => '100', 'subtract' => '1', 'max' => '9999'],
        1 => ['up_to' => '', 'method' => 'nearest', 'multiple' => '100', 'subtract' => '0', 'max' => '', 'remove' => 1],
      ],
    ]);
    $config = $this->config(PriceRules::CONFIG);
    $this->assertSame([['com']], array_column($config->get('rules'), 'extensions'));
    $this->assertSame([10000.0], array_column($config->get('rounding.steps'), 'up_to'));

    // The page shows what was saved, and an empty row for each table.
    $form = $this->container->get('form_builder')->getForm(SettingsForm::class);
    $this->assertSame('com', $form['pricing_rules']['rules'][0]['extensions']['#default_value']);
    $this->assertSame('', $form['pricing_rules']['rules'][1]['extensions']['#default_value']);
    $this->assertArrayNotHasKey(2, $form['pricing_rules']['rules']);
    $this->assertEquals(10000, $form['rounding']['steps'][0]['up_to']['#default_value']);
    $this->render($form);
    $this->assertText('Pricing rules');
    $this->assertText('Prices in other currencies');
  }

  /**
   * Tests the errors for rules and steps that don't add up.
   */
  public function testValidation(): void {
    $errors = $this->submit([
      'rules' => [
        0 => $this->ruleRow([
          'extensions' => 'com, -bad-',
          'length' => ['min' => '6', 'max' => '3'],
          'price' => ['number' => '', 'currency_code' => 'USD'],
        ]),
      ],
      'steps' => [
        0 => ['up_to' => '100', 'method' => 'up', 'multiple' => '', 'subtract' => '1', 'max' => ''],
      ],
    ], FALSE);
    $this->assertEquals([
      'rules][0][extensions' => '<em class="placeholder">-bad-</em> is not an extension.',
      'rules][0][length][max' => 'The longest label must be at least the shortest.',
      'rules][0][price][number' => 'Enter a price for the new rule, or clear its row.',
      'steps][0][multiple' => 'Enter the multiple to round to.',
    ], array_map('strval', $errors));
    $this->assertSame([], $this->config(PriceRules::CONFIG)->get('rules'), 'Nothing was saved.');
  }

  /**
   * Returns the values of a rules row, with the empty ones filled in.
   */
  protected function ruleRow(array $values): array {
    return $values + [
      'extensions' => '',
      'length' => ['min' => '', 'max' => ''],
      'words' => ['min' => '', 'max' => ''],
      'atom_premium' => '',
      'price' => ['number' => '', 'currency_code' => 'USD'],
      'weight' => 0,
    ];
  }

  /**
   * Submits the settings form.
   *
   * @return array
   *   The form errors, keyed by element name.
   */
  protected function submit(array $values, bool $valid = TRUE): array {
    $form_state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $errors = $form_state->getErrors();
    if ($valid) {
      $this->assertSame([], array_map('strval', $errors));
    }
    return $errors;
  }

}

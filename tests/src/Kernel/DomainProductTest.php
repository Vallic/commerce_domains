<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains\Kernel;

use Drupal\commerce_domains\Entity\Provider;
use Drupal\commerce_domains\EventSubscriber\ProductSubscriber;
use Drupal\commerce_domains\Plugin\Commerce\EntityTrait\AtomPremium;
use Drupal\commerce_domains\Plugin\Commerce\EntityTrait\Domain;
use Drupal\commerce_domains\Plugin\Validation\Constraint\DomainTitleConstraint;
use Drupal\commerce_domains\Plugin\Validation\Constraint\DomainTitleConstraintValidator;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests domain products: their fields, derivation and validation.
 */
#[CoversClass(Domain::class)]
#[CoversClass(AtomPremium::class)]
#[CoversClass(ProductSubscriber::class)]
#[CoversClass(DomainTitleConstraintValidator::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class DomainProductTest extends DomainsKernelTestBase {

  // The fields the two traits add, with their types.
  protected const FIELDS = [
    'domain' => 'string',
    'domain_label' => 'string',
    'domain_extension' => 'string',
    'domain_registrar' => 'entity_reference',
    'domain_expires' => 'timestamp',
    'domain_verified' => 'boolean',
    'domain_internal' => 'boolean',
    'atom_premium' => 'boolean',
  ];

  /**
   * Tests that the traits add their fields.
   */
  public function testFields(): void {
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('commerce_product', 'default');
    foreach (self::FIELDS as $name => $type) {
      $this->assertArrayHasKey($name, $fields);
      $this->assertSame($type, $fields[$name]->getType(), $name);
    }
    $this->assertSame('commerce_domains_provider', $fields['domain_registrar']->getSetting('target_type'));

    // Derived fields stay off the form; the editable ones are on it.
    $form = $this->container->get('entity_display.repository')->getFormDisplay('commerce_product', 'default');
    foreach (['domain', 'domain_label', 'domain_extension'] as $name) {
      $this->assertNull($form->getComponent($name), $name);
    }
    $this->assertSame('options_select', $form->getComponent('domain_registrar')['type']);
    // Nothing is added to the product page.
    $view = $this->container->get('entity_display.repository')->getViewDisplay('commerce_product', 'default');
    foreach (array_keys(self::FIELDS) as $name) {
      $this->assertNull($view->getComponent($name), $name);
    }
  }

  /**
   * Tests that the name fields follow the title.
   */
  public function testDerivedFromTitle(): void {
    $product = $this->createDomain('GiftCase.co.uk');
    $this->assertSame('giftcase.co.uk', $product->get('domain')->value);
    $this->assertSame('giftcase', $product->get('domain_label')->value);
    $this->assertSame('co.uk', $product->get('domain_extension')->value);

    $product->setTitle('München.de')->save();
    $this->assertSame('xn--mnchen-3ya.de', $product->get('domain')->value);
    $this->assertSame('de', $product->get('domain_extension')->value);
  }

  /**
   * Tests the registrar reference and the Atom premium flag.
   */
  public function testRegistrarAndPremium(): void {
    Provider::create(['id' => 'gname', 'label' => 'GNAME'])->save();
    $product = $this->createDomain('a.com', 1000, [
      'domain_registrar' => 'gname',
      'atom_premium' => TRUE,
      'domain_verified' => TRUE,
    ]);
    $product = Product::load($product->id());
    $this->assertSame('GNAME', $product->get('domain_registrar')->entity->label());
    $this->assertTrue((bool) $product->get('atom_premium')->value);
    $this->assertTrue((bool) $product->get('domain_verified')->value);
  }

  /**
   * Tests that only domain product types require a domain name as title.
   */
  public function testTitleValidation(): void {
    $product = $this->createDomain('a.com');
    $this->assertCount(0, $product->validate());

    foreach (['not a domain', 'www.a.com', 'localhost'] as $title) {
      $violations = $product->setTitle($title)->validate();
      $this->assertCount(1, $violations, $title);
      $this->assertSame('title', $violations[0]->getPropertyPath());
      $this->assertStringContainsString('is not a domain name', (string) $violations[0]->getMessage());
    }

    ProductType::create(['id' => 'merch', 'label' => 'Merch', 'variationTypes' => ['default']])->save();
    $shirt = Product::create(['type' => 'merch', 'title' => 'A T-shirt', 'stores' => [$this->store]]);
    $ours = array_filter(iterator_to_array($shirt->validate()), fn($violation) => $violation->getConstraint() instanceof DomainTitleConstraint);
    $this->assertSame([], $ours, 'Other product types may have any title.');
    $shirt->save();
    $this->assertFalse($shirt->hasField('domain'));
  }

}

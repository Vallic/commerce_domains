<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_domains_pricing\Kernel;

use Drupal\commerce_domains_pricing\Rounding;
use Drupal\commerce_price\Price;
use Drupal\Tests\commerce_domains\Kernel\DomainsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rounding with steps, NameAcre's rule among them.
 */
#[CoversClass(Rounding::class)]
#[Group('commerce_domains')]
#[RunTestsInSeparateProcesses]
class RoundingTest extends DomainsKernelTestBase {

  use PricingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_domains_pricing'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['commerce_domains_pricing']);
  }

  /**
   * Tests NameAcre's examples: up to 10,000 end in 99, above to the next 100.
   */
  public function testNameAcreExamples(): void {
    $examples = [
      ['1234', '1299'],
      ['1200', '1199'],
      ['1201', '1299'],
      ['1', '99'],
      ['99', '99'],
      ['100', '99'],
      ['101', '199'],
      ['9950', '9999'],
      ['10000', '9999'],
      ['10001', '10000'],
      ['10049', '10000'],
      ['10050', '10100'],
      ['12345', '12300'],
      ['12350', '12400'],
      // The cents go first, as NameAcre's cast to an integer drops them.
      ['1234.56', '1299'],
      ['1200.50', '1199'],
      ['10000.99', '9999'],
    ];
    foreach ($examples as [$amount, $rounded]) {
      $this->assertSame($rounded, Rounding::roundNumber($amount, self::$nameAcreSteps, TRUE), $amount);
    }
    $this->assertSame('1299', Rounding::roundNumber('1200.50', self::$nameAcreSteps), 'Kept, the cents push 1,200.50 over 1,200.');
    $this->assertSame('0', Rounding::roundNumber('0', self::$nameAcreSteps, TRUE), 'Never below zero.');
  }

  /**
   * Tests that the steps give NameAcre's numbers for any price.
   */
  public function testSameAsNameAcre(): void {
    for ($amount = 1; $amount <= 30000; $amount += 7) {
      foreach (['', '.01', '.5', '.99'] as $cents) {
        $number = $amount . $cents;
        $this->assertSame((string) self::nameAcreRound((int) $number), Rounding::roundNumber($number, self::$nameAcreSteps, TRUE), $number);
      }
    }
    foreach ([1000000, 1234567, 99999999] as $amount) {
      $this->assertSame((string) self::nameAcreRound($amount), Rounding::roundNumber((string) $amount, self::$nameAcreSteps, TRUE));
    }
  }

  /**
   * Tests rounding down and to the nearest, limits, caps and decimals.
   */
  public function testSteps(): void {
    $steps = [
      // Listed out of order: the lowest limit is tried first.
      ['up_to' => NULL, 'method' => 'down', 'multiple' => 1000, 'subtract' => 0, 'max' => NULL],
      ['up_to' => 100, 'method' => 'nearest', 'multiple' => 5, 'subtract' => 0.01, 'max' => NULL],
      ['up_to' => 5000, 'method' => 'up', 'multiple' => 500, 'subtract' => 1, 'max' => 3999],
    ];
    $this->assertSame('39.99', Rounding::roundNumber('38', $steps));
    $this->assertSame('34.99', Rounding::roundNumber('37.49', $steps));
    $this->assertSame('99.99', Rounding::roundNumber('100', $steps));
    $this->assertSame('1499', Rounding::roundNumber('1001', $steps));
    $this->assertSame('1999', Rounding::roundNumber('1500.01', $steps));
    $this->assertSame('3999', Rounding::roundNumber('4600', $steps), 'Capped at 3,999.');
    $this->assertSame('12000', Rounding::roundNumber('12999.99', $steps));

    // Above every limit, an amount is left alone.
    $this->assertSame('250.5', Rounding::roundNumber('250.5', [['up_to' => 100, 'method' => 'up', 'multiple' => 10]]));
    $this->assertSame('250.5', Rounding::roundNumber('250.5', []));
  }

  /**
   * Tests rounding prices with the steps from the settings.
   */
  public function testRoundPrice(): void {
    $rounding = $this->container->get(Rounding::class);
    $this->assertPrice('1234.57', 'USD', $rounding->round(new Price('1234.5678', 'USD')), "Without steps, to the currency's decimals.");

    $this->setNameAcreRounding();
    $this->assertPrice('1299', 'USD', $rounding->round(new Price('1234.5678', 'USD')));
    $this->assertPrice('150000', 'EUR', $rounding->round(new Price('149960', 'EUR')));
  }

}

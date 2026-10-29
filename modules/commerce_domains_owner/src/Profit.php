<?php

declare(strict_types=1);

namespace Drupal\commerce_domains_owner;

use Drupal\commerce_price\Price;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * What a sold domain earned; see Costs for the formula.
 */
final class Profit {

  public function __construct(
    // What it sold for; NULL until it sells.
    public readonly ?Price $salePrice,
    // What it cost, in its own currency.
    public readonly ?Price $purchasePrice,
    // How many renewals were paid while it was held.
    public readonly int $renewals,
    // Those renewals at the yearly renewal cost, in its currency; NULL
    // without a renewal cost.
    public readonly ?Price $renewalsPaid,
    // In the sale's currency; NULL when it can't be worked out.
    public readonly ?Price $profit,
    // Why there is no profit, for people.
    public readonly ?TranslatableMarkup $problem = NULL,
  ) {}

}

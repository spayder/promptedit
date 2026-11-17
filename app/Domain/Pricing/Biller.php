<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Generation\TokenUsage;

/** Turns a finished generation's usage and its frozen rates into the amounts to record. */
final readonly class Biller
{
    public function __construct(
        private CostCalculator $costs,
        private MarkupPolicy $markup,
    ) {
    }

    public function bill(TokenUsage $usage, Rates $rates): Bill
    {
        $providerCost = $this->costs->providerCost($usage, $rates);

        return new Bill($providerCost, $this->markup->apply($providerCost));
    }
}

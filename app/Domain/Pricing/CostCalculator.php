<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Generation\TokenUsage;
use App\Domain\Money;

/**
 * Pixelforge's exact cost for a generation:
 * sum(tokens of each type * that type's rate per token).
 *
 * Pass the rates frozen on the generation, never "current" rates. Whether the
 * generation is billable at all (failed ones are free) is decided by the
 * Generation, not here.
 */
final class CostCalculator
{
    public function providerCost(TokenUsage $usage, Rates $rates): Money
    {
        return Money::nanos($rates->inputTextNanos)->multiply($usage->inputTextTokens)
            ->add(Money::nanos($rates->inputImageNanos)->multiply($usage->inputImageTokens))
            ->add(Money::nanos($rates->outputNanos)->multiply($usage->outputTokens))
            ->add(Money::nanos($rates->reasoningNanos)->multiply($usage->reasoningTokens));
    }
}

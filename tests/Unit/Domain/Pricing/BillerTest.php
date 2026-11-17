<?php

use App\Domain\Generation\TokenUsage;
use App\Domain\Money;
use App\Domain\Pricing\Biller;
use App\Domain\Pricing\CostCalculator;
use App\Domain\Pricing\MarkupPolicy;
use Tests\Fixtures\PixelforgePricing;

function biller(int $percent = 25): Biller
{
    return new Biller(new CostCalculator, MarkupPolicy::percent($percent));
}

describe('MarkupPolicy', function () {
    it('adds the percentage, rounding up to the next nanodollar', function () {
        $policy = MarkupPolicy::percent(25);

        expect($policy->apply(Money::fromDecimal('0.373875000'))->toDecimal())->toBe('0.467343750')
            ->and($policy->apply(Money::nanos(1))->toNanos())->toBe(2)
            ->and($policy->apply(Money::zero())->isZero())->toBeTrue();
    });

    it('can be configured with another percentage', function () {
        expect(MarkupPolicy::percent(0)->apply(Money::nanos(7))->toNanos())->toBe(7)
            ->and(MarkupPolicy::percent(100)->apply(Money::nanos(7))->toNanos())->toBe(14);
    });

    it('rejects a negative percentage', function () {
        MarkupPolicy::percent(-1);
    })->throws(InvalidArgumentException::class);
});

describe('Biller', function () {
    it('bills the provider cost and the user charge for a usage', function () {
        $usage = new TokenUsage(inputTextTokens: 115, inputImageTokens: 1_530, outputTokens: 6_450, reasoningTokens: 8_000);

        $bill = biller()->bill($usage, PixelforgePricing::rates());

        expect($bill->providerCost->toDecimal())->toBe('0.373875000')
            ->and($bill->charge->toDecimal())->toBe('0.467343750');
    });

    it('never charges the user less than the provider cost', function () {
        $usage = new TokenUsage(inputTextTokens: 1, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 0);

        $bill = biller()->bill($usage, PixelforgePricing::rates());

        expect($bill->charge->toNanos())->toBeGreaterThanOrEqual($bill->providerCost->toNanos());
    });

    it('bills nothing for zero usage', function () {
        $bill = biller()->bill(new TokenUsage(0, 0, 0, 0), PixelforgePricing::rates());

        expect($bill->providerCost->isZero())->toBeTrue()
            ->and($bill->charge->isZero())->toBeTrue();
    });
});

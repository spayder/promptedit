<?php

use App\Domain\Generation\TokenUsage;
use App\Domain\Money;
use App\Domain\Pricing\CostCalculator;
use App\Domain\Pricing\Rates;

function pixelforgeRates(): Rates
{
    return Rates::fromPerMillionTokens(inputText: '5.00', inputImage: '10.00', output: '40.00', reasoning: '12.50');
}

describe('TokenUsage', function () {
    it('totals all token types', function () {
        $usage = new TokenUsage(inputTextTokens: 115, inputImageTokens: 1_530, outputTokens: 6_450, reasoningTokens: 8_000);

        expect($usage->totalTokens())->toBe(16_095);
    });

    it('rejects negative token counts', function () {
        new TokenUsage(inputTextTokens: -1, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 0);
    })->throws(InvalidArgumentException::class);
});

describe('CostCalculator', function () {
    it('matches the maximum cost worked example from the Pixelforge docs', function () {
        // high quality, 1536x1024, 400 chars (+15% = 115 tokens), 2 reference images, max reasoning
        $usage = new TokenUsage(inputTextTokens: 115, inputImageTokens: 1_530, outputTokens: 6_450, reasoningTokens: 8_000);

        $cost = (new CostCalculator)->providerCost($usage, pixelforgeRates());

        expect($cost->toDecimal())->toBe('0.373875000');
    });

    it('prices each token type at its own rate', function () {
        $calculator = new CostCalculator;
        $rates = pixelforgeRates();

        expect($calculator->providerCost(new TokenUsage(115, 0, 0, 0), $rates)->toDecimal())->toBe('0.000575000')
            ->and($calculator->providerCost(new TokenUsage(0, 1_530, 0, 0), $rates)->toDecimal())->toBe('0.015300000')
            ->and($calculator->providerCost(new TokenUsage(0, 0, 6_450, 0), $rates)->toDecimal())->toBe('0.258000000')
            ->and($calculator->providerCost(new TokenUsage(0, 0, 0, 8_000), $rates)->toDecimal())->toBe('0.100000000');
    });

    it('prices a typical medium generation exactly', function () {
        // 10 text + 1100 output + 1000 reasoning = 50_000 + 44_000_000 + 12_500_000 nanodollars
        $usage = new TokenUsage(inputTextTokens: 10, inputImageTokens: 0, outputTokens: 1_100, reasoningTokens: 1_000);

        expect((new CostCalculator)->providerCost($usage, pixelforgeRates())->toDecimal())->toBe('0.056550000');
    });

    it('costs nothing for zero usage', function () {
        $cost = (new CostCalculator)->providerCost(new TokenUsage(0, 0, 0, 0), pixelforgeRates());

        expect($cost)->toEqual(Money::zero());
    });

    it('uses the rates it is given, not hardcoded ones', function () {
        $usage = new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 1_000_000, reasoningTokens: 0);
        $newRates = Rates::fromPerMillionTokens(inputText: '5.00', inputImage: '10.00', output: '50.00', reasoning: '12.50');

        $calculator = new CostCalculator;

        expect($calculator->providerCost($usage, pixelforgeRates())->toDecimal())->toBe('40.000000000')
            ->and($calculator->providerCost($usage, $newRates)->toDecimal())->toBe('50.000000000');
    });

    it('applies the 25% markup on top of the exact provider cost', function () {
        $usage = new TokenUsage(inputTextTokens: 115, inputImageTokens: 1_530, outputTokens: 6_450, reasoningTokens: 8_000);

        $charge = (new CostCalculator)->providerCost($usage, pixelforgeRates())->withMarkup(25);

        expect($charge->toDecimal())->toBe('0.467343750');
    });
});

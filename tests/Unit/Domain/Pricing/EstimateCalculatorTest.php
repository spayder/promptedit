<?php

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Domain\Generation\TokenUsage;
use App\Domain\Pricing\CostCalculator;
use App\Domain\Pricing\EstimateCalculator;
use Tests\Fixtures\PixelforgePricing;

function estimate(string $prompt, Quality $quality, ImageSize $size, int $referenceImages = 0)
{
    return (new EstimateCalculator(PixelforgePricing::schedule(), new CostCalculator))
        ->estimate($prompt, $quality, $size, $referenceImages, PixelforgePricing::rates());
}

it('matches the maximum cost worked example from the Pixelforge docs', function () {
    // high, 1536x1024, 400 chars, 2 reference images, maximum reasoning
    $estimate = estimate(str_repeat('a', 400), Quality::High, ImageSize::Landscape, referenceImages: 2);

    expect($estimate->max->toDecimal())->toBe('0.373875000')
        // min: 85 text tokens + 2 images + 6450 output + 1000 reasoning
        ->and($estimate->min->toDecimal())->toBe('0.286225000');
});

it('has a narrow range for a low quality, empty prompt', function () {
    $estimate = estimate('', Quality::Low, ImageSize::Square);

    // 280 output tokens, reasoning 0..500
    expect($estimate->min->toDecimal())->toBe('0.011200000')
        ->and($estimate->max->toDecimal())->toBe('0.017450000');
});

it('widens the text token interval outward so it always contains the true count', function () {
    // 4 chars = 1 token; +/-15% -> floor(0.85) = 0 .. ceil(1.15) = 2
    $estimate = estimate('abcd', Quality::Medium, ImageSize::Square);

    // min: 0 text + 1100 output + 200 reasoning; max: 2 text + 1100 output + 2000 reasoning
    expect($estimate->min->toDecimal())->toBe('0.046500000')
        ->and($estimate->max->toDecimal())->toBe('0.069010000');
});

it('rounds partial text tokens up', function () {
    expect(estimate('a', Quality::Low, ImageSize::Square)->max->toNanos())
        ->toBe(estimate('abcd', Quality::Low, ImageSize::Square)->max->toNanos())
        ->and(estimate('abcde', Quality::Low, ImageSize::Square)->max->toNanos())
        ->toBeGreaterThan(estimate('abcd', Quality::Low, ImageSize::Square)->max->toNanos());
});

it('counts unicode characters, not bytes', function () {
    expect(estimate(str_repeat('é', 400), Quality::High, ImageSize::Square)->max->toNanos())
        ->toBe(estimate(str_repeat('a', 400), Quality::High, ImageSize::Square)->max->toNanos());
});

it('bills each reference image at the fixed image token count', function () {
    $without = estimate('', Quality::Low, ImageSize::Square);
    $with = estimate('', Quality::Low, ImageSize::Square, referenceImages: 4);

    // 4 * 765 tokens * 10_000 nano
    expect($with->min->subtract($without->min)->toNanos())->toBe(30_600_000)
        ->and($with->max->subtract($without->max)->toNanos())->toBe(30_600_000);
});

it('applies the markup to both ends of the range', function () {
    $estimate = estimate(str_repeat('a', 400), Quality::High, ImageSize::Landscape, referenceImages: 2)
        ->withMarkup(25);

    expect($estimate->max->toDecimal())->toBe('0.467343750')
        ->and($estimate->min->toDecimal())->toBe('0.357781250');
});

it('rejects a negative reference image count', function () {
    estimate('x', Quality::Low, ImageSize::Square, referenceImages: -1);
})->throws(InvalidArgumentException::class);

it('never has min above max and always contains every possible actual cost', function (Quality $quality, ImageSize $size) {
    $schedule = PixelforgePricing::schedule();
    $rates = PixelforgePricing::rates();
    $calculator = new CostCalculator;
    $prompt = str_repeat('x', 333); // 84 tokens, actual may be 71..97
    $estimate = estimate($prompt, $quality, $size, referenceImages: 3);

    expect($estimate->min->toNanos())->toBeLessThanOrEqual($estimate->max->toNanos());

    $reasoning = $schedule->reasoningRange($quality);

    foreach ([71, 84, 97] as $text) {
        foreach ([$reasoning['min'], intdiv($reasoning['min'] + $reasoning['max'], 2), $reasoning['max']] as $thinking) {
            $actual = $calculator->providerCost(
                new TokenUsage($text, 3 * 765, $schedule->outputTokens($quality, $size), $thinking),
                $rates,
            );

            expect($actual->toNanos())
                ->toBeGreaterThanOrEqual($estimate->min->toNanos())
                ->toBeLessThanOrEqual($estimate->max->toNanos());
        }
    }
})->with(function () {
    foreach (Quality::cases() as $quality) {
        foreach (ImageSize::cases() as $size) {
            yield "{$quality->value} {$size->value}" => [$quality, $size];
        }
    }
});

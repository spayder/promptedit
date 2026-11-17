<?php

use App\Domain\Pricing\Rates;

it('converts Pixelforge per-1M-token rates to nanodollars per token', function () {
    $rates = Rates::fromPerMillionTokens(
        inputText: '5.00',
        inputImage: '10.00',
        output: '40.00',
        reasoning: '12.50',
    );

    expect($rates->inputTextNanos)->toBe(5_000)
        ->and($rates->inputImageNanos)->toBe(10_000)
        ->and($rates->outputNanos)->toBe(40_000)
        ->and($rates->reasoningNanos)->toBe(12_500);
});

it('accepts up to three decimals, the finest rate that is a whole nanodollar per token', function () {
    $rates = Rates::fromPerMillionTokens(inputText: '5.001', inputImage: '0', output: '0.001', reasoning: '12');

    expect($rates->inputTextNanos)->toBe(5_001)
        ->and($rates->inputImageNanos)->toBe(0)
        ->and($rates->outputNanos)->toBe(1)
        ->and($rates->reasoningNanos)->toBe(12_000);
});

it('rejects a rate that is not a whole nanodollar per token instead of rounding it', function () {
    Rates::fromPerMillionTokens(inputText: '5.0005', inputImage: '10.00', output: '40.00', reasoning: '12.50');
})->throws(InvalidArgumentException::class);

it('rejects negative and malformed rates', function (string $bad) {
    Rates::fromPerMillionTokens(inputText: $bad, inputImage: '10.00', output: '40.00', reasoning: '12.50');
})->with(['negative' => ['-5.00'], 'text' => ['five'], 'empty' => ['']])
    ->throws(InvalidArgumentException::class);

it('can be rebuilt from stored nanodollars per token', function () {
    $rates = new Rates(inputTextNanos: 5_000, inputImageNanos: 10_000, outputNanos: 40_000, reasoningNanos: 12_500);

    expect($rates->outputNanos)->toBe(40_000);
});

it('rejects negative nanodollar rates', function () {
    new Rates(inputTextNanos: -1, inputImageNanos: 0, outputNanos: 0, reasoningNanos: 0);
})->throws(InvalidArgumentException::class);

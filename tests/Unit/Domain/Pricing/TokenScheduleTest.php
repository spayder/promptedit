<?php

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Domain\Pricing\TokenSchedule;
use Tests\Fixtures\PixelforgePricing;

it('exposes exact output tokens by quality and size', function () {
    $schedule = PixelforgePricing::schedule();

    expect($schedule->outputTokens(Quality::Low, ImageSize::Square))->toBe(280)
        ->and($schedule->outputTokens(Quality::Medium, ImageSize::Landscape))->toBe(1_650)
        ->and($schedule->outputTokens(Quality::High, ImageSize::Portrait))->toBe(6_450);
});

it('exposes the reasoning token range by quality', function () {
    $schedule = PixelforgePricing::schedule();

    expect($schedule->reasoningRange(Quality::Low))->toBe(['min' => 0, 'max' => 500])
        ->and($schedule->reasoningRange(Quality::High))->toBe(['min' => 1_000, 'max' => 8_000]);
});

it('refuses a table that is missing a quality/size combination', function () {
    $tokens = PixelforgePricing::response()['tokens'];
    unset($tokens['output']['high']['1536x1024']);

    new TokenSchedule(4, 15, 765, $tokens['output'], $tokens['reasoning']);
})->throws(InvalidArgumentException::class);

it('refuses a missing or inverted reasoning range', function () {
    $tokens = PixelforgePricing::response()['tokens'];
    $tokens['reasoning']['medium'] = ['min' => 2_000, 'max' => 200];

    new TokenSchedule(4, 15, 765, $tokens['output'], $tokens['reasoning']);
})->throws(InvalidArgumentException::class);

it('refuses nonsensical scalar settings', function (int $chars, int $variance, int $imageTokens) {
    $tokens = PixelforgePricing::response()['tokens'];

    new TokenSchedule($chars, $variance, $imageTokens, $tokens['output'], $tokens['reasoning']);
})->with([
    'zero characters per token' => [0, 15, 765],
    'negative variance' => [4, -1, 765],
    'variance over 100' => [4, 101, 765],
    'negative image tokens' => [4, 15, -1],
])->throws(InvalidArgumentException::class);

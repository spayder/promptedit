<?php

use App\Application\Generation\QuoteGeneration;
use App\Application\Ports\ImageProvider;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\FakeImageProvider;

beforeEach(function () {
    Cache::flush();
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
});

function quote(string $prompt, Quality $quality, ImageSize $size, int $refs = 0): array
{
    $estimate = app(QuoteGeneration::class)->quote($prompt, $quality, $size, $refs);

    return [$estimate->min->toNanos(), $estimate->max->toNanos()];
}

it('prices an empty low-quality square: output is exact, reasoning is 0..500 tokens, plus 25%', function () {
    // provider: 280 output = 11,200,000; reasoning adds up to 500 * 12,500 = 6,250,000
    expect(quote('', Quality::Low, ImageSize::Square))->toBe([
        14_000_000, // 11,200,000 * 1.25
        21_812_500, // 17,450,000 * 1.25
    ]);
});

it('widens the range by the text-token variance', function () {
    // 40 chars = 10 text tokens, actual somewhere in 8..12
    expect(quote(str_repeat('a', 40), Quality::Low, ImageSize::Square))->toBe([
        14_050_000, // (8 * 5,000 + 11,200,000) * 1.25
        21_887_500, // (12 * 5,000 + 17,450,000) * 1.25
    ]);
});

it('counts reference images at 765 tokens each', function () {
    [$withoutMin] = quote('', Quality::Low, ImageSize::Square, 0);
    [$withMin] = quote('', Quality::Low, ImageSize::Square, 2);

    // 2 * 765 * 10,000 = 15,300,000 provider, * 1.25 = 19,125,000
    expect($withMin - $withoutMin)->toBe(19_125_000);
});

it('does not call Pixelforge for pricing on every quote', function () {
    quote('one', Quality::Low, ImageSize::Square);
    quote('two', Quality::High, ImageSize::Portrait);

    expect($this->provider->pricingCalls)->toBe(1);
});

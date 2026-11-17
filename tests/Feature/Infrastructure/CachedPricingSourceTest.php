<?php

use App\Application\Ports\PricingSource;
use App\Application\Provider\Exceptions\ProviderUnavailable;
use App\Infrastructure\Pixelforge\CachedPricingSource;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\FakeImageProvider;

function cachedPricing(FakeImageProvider $provider, int $ttl = 3600): CachedPricingSource
{
    return new CachedPricingSource($provider, Cache::store(), $ttl);
}

beforeEach(function () {
    Cache::flush();
    config(['services.pixelforge.api_key' => 'pf_test_key']);
});

it('is what the container hands out as the pricing source', function () {
    expect(app(PricingSource::class))->toBeInstanceOf(CachedPricingSource::class);
});

it('fetches pricing once and then serves it from the cache', function () {
    $provider = new FakeImageProvider;
    $source = cachedPricing($provider);

    $first = $source->current();
    $second = $source->current();

    expect($provider->pricingCalls)->toBe(1)
        ->and($second->rates->outputNanos)->toBe($first->rates->outputNanos)
        ->and($first->rates->inputTextNanos)->toBe(5_000);
});

it('fetches again once the cached copy has expired', function () {
    $provider = new FakeImageProvider;
    $source = cachedPricing($provider, ttl: 60);

    $source->current();
    $this->travel(61)->seconds();
    $source->current();

    expect($provider->pricingCalls)->toBe(2);
});

it('falls back to the last known pricing when Pixelforge is down', function () {
    $provider = new FakeImageProvider;
    $source = cachedPricing($provider, ttl: 60);
    $source->current();

    $this->travel(61)->seconds();
    $provider->pricingFailure = new ProviderUnavailable('down', 5);

    expect($source->current()->rates->outputNanos)->toBe(40_000);
});

it('fails when Pixelforge is down and nothing was ever fetched', function () {
    $provider = new FakeImageProvider;
    $provider->pricingFailure = new ProviderUnavailable('down', 5);

    expect(fn () => cachedPricing($provider)->current())->toThrow(ProviderUnavailable::class);
});

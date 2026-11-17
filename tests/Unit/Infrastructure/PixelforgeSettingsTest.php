<?php

use App\Infrastructure\Pixelforge\PixelforgeSettings;

function settingsConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'base_url' => 'https://pixelforge.test/v1',
        'api_key' => 'pf_key',
        'timeout' => 10,
        'connect_timeout' => 5,
        'retry_after' => ['rate_limit' => 10, 'concurrency' => 5, 'overloaded' => 2, 'unavailable' => 5, 'max' => 3600],
    ], $overrides);
}

describe('PixelforgeSettings', function () {
    it('is built from the services.pixelforge config block', function () {
        $settings = PixelforgeSettings::fromConfig(settingsConfig());

        expect($settings->baseUrl)->toBe('https://pixelforge.test/v1')
            ->and($settings->apiKey)->toBe('pf_key')
            ->and($settings->timeoutSeconds)->toBe(10)
            ->and($settings->connectTimeoutSeconds)->toBe(5)
            ->and($settings->retryRateLimit)->toBe(10)
            ->and($settings->retryConcurrency)->toBe(5)
            ->and($settings->retryOverloaded)->toBe(2)
            ->and($settings->retryUnavailable)->toBe(5)
            ->and($settings->retryMax)->toBe(3600);
    });

    it('rejects a blank API key', function (?string $key) {
        expect(fn () => PixelforgeSettings::fromConfig(settingsConfig(['api_key' => $key])))
            ->toThrow(InvalidArgumentException::class, 'API key');
    })->with([[null], [''], ['   ']]);

    it('rejects durations below one second', function (string $path) {
        $overrides = str_contains($path, '.')
            ? [explode('.', $path)[0] => [explode('.', $path)[1] => 0]]
            : [$path => 0];

        expect(fn () => PixelforgeSettings::fromConfig(settingsConfig($overrides)))
            ->toThrow(InvalidArgumentException::class, 'at least 1');
    })->with(['timeout', 'connect_timeout', 'retry_after.rate_limit', 'retry_after.concurrency', 'retry_after.overloaded', 'retry_after.unavailable', 'retry_after.max']);
});

<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use InvalidArgumentException;

/**
 * Everything the client needs to know, read once from config/services.php so
 * the client itself never touches the framework's config.
 */
final readonly class PixelforgeSettings
{
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public int $timeoutSeconds,
        public int $connectTimeoutSeconds,
        /** Used when Pixelforge sends no usable Retry-After header. */
        public int $retryRateLimit,
        public int $retryConcurrency,
        public int $retryOverloaded,
        public int $retryUnavailable,
        /** Upper bound for any wait, whatever Retry-After says. */
        public int $retryMax,
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('The Pixelforge API key is empty.');
        }

        foreach (compact('timeoutSeconds', 'connectTimeoutSeconds', 'retryRateLimit', 'retryConcurrency', 'retryOverloaded', 'retryUnavailable', 'retryMax') as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Pixelforge setting {$name} must be at least 1.");
            }
        }
    }

    /** @param  array<string, mixed>  $config  the services.pixelforge block */
    public static function fromConfig(array $config): self
    {
        $retry = $config['retry_after'];

        return new self(
            baseUrl: (string) $config['base_url'],
            apiKey: (string) ($config['api_key'] ?? ''),
            timeoutSeconds: (int) $config['timeout'],
            connectTimeoutSeconds: (int) $config['connect_timeout'],
            retryRateLimit: (int) $retry['rate_limit'],
            retryConcurrency: (int) $retry['concurrency'],
            retryOverloaded: (int) $retry['overloaded'],
            retryUnavailable: (int) $retry['unavailable'],
            retryMax: (int) $retry['max'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use App\Application\Ports\ImageProvider;
use App\Application\Ports\PricingSource;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\PricingSnapshot;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pricing changes rarely, so it is fetched at most once per TTL rather than on
 * every page view. The last good copy is also kept without an expiry, so a
 * Pixelforge outage does not stop people seeing an estimate.
 */
final class CachedPricingSource implements PricingSource
{
    private const string FRESH_KEY = 'pixelforge.pricing';

    private const string LAST_KNOWN_KEY = 'pixelforge.pricing.last_known';

    public function __construct(
        private readonly ImageProvider $provider,
        private readonly Cache $cache,
        private readonly int $ttlSeconds,
    ) {
    }

    public function current(): PricingSnapshot
    {
        $fresh = $this->cache->get(self::FRESH_KEY);

        if ($fresh instanceof PricingSnapshot) {
            return $fresh;
        }

        try {
            $snapshot = $this->provider->pricing();
        } catch (ProviderException $e) {
            $lastKnown = $this->cache->get(self::LAST_KNOWN_KEY);

            if (! $lastKnown instanceof PricingSnapshot) {
                throw $e;
            }

            Log::warning('Pixelforge pricing unavailable, using the last known copy.', ['error' => $e->getMessage()]);

            return $lastKnown;
        }

        $this->cache->put(self::FRESH_KEY, $snapshot, $this->ttlSeconds);
        $this->cache->forever(self::LAST_KNOWN_KEY, $snapshot);

        return $snapshot;
    }
}

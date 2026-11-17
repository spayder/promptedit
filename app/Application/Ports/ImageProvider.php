<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Provider\CreateGenerationRequest;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\PricingSnapshot;
use App\Application\Provider\ProviderGeneration;
use App\Application\Provider\ProviderUsage;

/**
 * The image-generation provider (Pixelforge). Every method throws a
 * {@see ProviderException}; the retryable ones extend
 * {@see \App\Application\Provider\Exceptions\RetryableProviderException} and say how long to wait.
 */
interface ImageProvider
{
    /**
     * Submit a generation. The idempotency key makes retries safe: the same key
     * with the same request returns the existing generation instead of creating
     * (and billing) another.
     *
     * @throws ProviderException
     */
    public function createGeneration(CreateGenerationRequest $request, string $idempotencyKey): ProviderGeneration;

    /** @throws ProviderException */
    public function getGeneration(string $providerId): ProviderGeneration;

    /** @throws ProviderException */
    public function pricing(): PricingSnapshot;

    /**
     * What Pixelforge billed for the API key in use, per UTC day (a generation
     * counts on the day it completed). This is the source of truth for provider
     * costs, so our own records are reconciled against it.
     *
     * @param  string  $startDate  YYYY-MM-DD, UTC, inclusive
     * @param  string  $endDate  YYYY-MM-DD, UTC, inclusive; at most 92 days after the start
     *
     * @throws ProviderException
     */
    public function usage(string $startDate, string $endDate): ProviderUsage;
}

<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Provider\CreateGenerationRequest;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\PricingSnapshot;
use App\Application\Provider\ProviderGeneration;

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
}

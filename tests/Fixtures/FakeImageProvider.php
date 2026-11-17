<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Application\Ports\ImageProvider;
use App\Application\Provider\CreateGenerationRequest;
use App\Application\Provider\Exceptions\ProviderNotFound;
use App\Application\Provider\PricingSnapshot;
use App\Application\Provider\ProviderGeneration;
use App\Infrastructure\Pixelforge\ResponseMapper;
use Closure;
use Throwable;

/** An in-memory ImageProvider for tests: records calls, can fail or run a hook on demand. */
final class FakeImageProvider implements ImageProvider
{
    /** @var list<array{request: CreateGenerationRequest, key: string}> */
    public array $created = [];

    public int $pricingCalls = 0;

    /** Thrown by createGeneration() when set. */
    public ?Throwable $createFailure = null;

    /** Thrown by pricing() when set. */
    public ?Throwable $pricingFailure = null;

    /** Runs inside createGeneration(), before it answers: use it to play out a race. */
    public ?Closure $whileCreating = null;

    public string $providerId = 'gen_test0001';

    /** What getGeneration() answers, by Pixelforge id. Anything else is a 404. */
    public array $remote = [];

    /** @var list<string> the ids getGeneration() was asked about, in order */
    public array $fetched = [];

    /** Thrown by getGeneration() for the ids listed in $getFailures, keyed by id. */
    public array $getFailures = [];

    public function createGeneration(CreateGenerationRequest $request, string $idempotencyKey): ProviderGeneration
    {
        $this->created[] = ['request' => $request, 'key' => $idempotencyKey];

        if ($this->whileCreating !== null) {
            ($this->whileCreating)($request, $idempotencyKey);
        }

        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        return ResponseMapper::generation(PixelforgeApi::generation('queued', [
            'id' => $this->providerId,
            'metadata' => $request->metadata,
        ]));
    }

    public function getGeneration(string $providerId): ProviderGeneration
    {
        $this->fetched[] = $providerId;

        if (isset($this->getFailures[$providerId])) {
            throw $this->getFailures[$providerId];
        }

        return $this->remote[$providerId] ?? throw new ProviderNotFound('No such resource.', 'not_found', 404);
    }

    public function pricing(): PricingSnapshot
    {
        $this->pricingCalls++;

        if ($this->pricingFailure !== null) {
            throw $this->pricingFailure;
        }

        return new PricingSnapshot(PixelforgePricing::rates(), PixelforgePricing::schedule());
    }
}

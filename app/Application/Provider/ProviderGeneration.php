<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\TokenUsage;
use App\Domain\Pricing\Rates;
use DateTimeImmutable;

/**
 * A generation as the provider reports it (from an API response or from the
 * generation object inside a webhook). Status is one of the four the provider
 * owns: queued, processing, succeeded, failed.
 */
final readonly class ProviderGeneration
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?Rates $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?DateTimeImmutable $imageExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
        public array $metadata,
    ) {
    }

    /** Our generation id, echoed back from the metadata we sent when creating it. */
    public function ourGenerationId(): ?string
    {
        return $this->metadata['generation_id'] ?? null;
    }
}

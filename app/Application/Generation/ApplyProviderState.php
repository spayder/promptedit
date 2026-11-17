<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Provider\Exceptions\UnexpectedResponse;
use App\Application\Provider\ProviderGeneration;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Pricing\Biller;
use Illuminate\Support\Facades\Date;

/**
 * Brings a generation in line with what Pixelforge reports about it.
 *
 * One place does this for everything that learns the provider's state: a
 * webhook, the answer to our create call, and the poller. They all go by the
 * reported status, never by which event happened to arrive, so a late
 * "processing" cannot undo "succeeded". The Generation itself refuses stale
 * and duplicate changes, which makes calling this repeatedly harmless.
 *
 * Call it inside GenerationRepository::update() so the row is locked.
 */
final class ApplyProviderState
{
    public function __construct(private readonly Biller $biller)
    {
    }

    /** @return bool whether anything changed (false for a duplicate or out-of-date report) */
    public function handle(Generation $generation, ProviderGeneration $remote): bool
    {
        $generation->linkProvider($remote->id);

        return match ($remote->status) {
            GenerationStatus::Queued => $generation->markQueued(),
            GenerationStatus::Processing => $generation->markProcessing(),
            GenerationStatus::Succeeded => $this->succeeded($generation, $remote),
            GenerationStatus::Failed => $generation->markFailed(
                $remote->errorCode ?? 'generation_failed',
                $remote->errorMessage ?? 'The image could not be generated.',
                $remote->completedAt ?? Date::now()->toDateTimeImmutable(),
            ),
            default => throw new UnexpectedResponse("Pixelforge reported an unusable status [{$remote->status->value}]."),
        };
    }

    private function succeeded(Generation $generation, ProviderGeneration $remote): bool
    {
        // Without these we cannot bill correctly, and guessing is worse than waiting for a retry.
        if ($remote->usage === null || $remote->rates === null || $remote->imageUrl === null
            || $remote->imageExpiresAt === null || $remote->completedAt === null) {
            throw new UnexpectedResponse("Pixelforge reported generation {$remote->id} as succeeded without usage, rates or image.");
        }

        return $generation->markSucceeded(
            biller: $this->biller,
            usage: $remote->usage,
            rates: $remote->rates, // the rates frozen on the generation, not today's
            imageUrl: $remote->imageUrl,
            imageExpiresAt: $remote->imageExpiresAt,
            completedAt: $remote->completedAt,
        );
    }
}

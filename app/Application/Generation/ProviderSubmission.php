<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageProvider;
use App\Application\Provider\CreateGenerationRequest;
use App\Application\Provider\Exceptions\ContentPolicyViolation;
use App\Application\Provider\Exceptions\DailyLimitExceeded;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\Exceptions\RetryableProviderException;
use App\Application\Provider\Exceptions\ValidationFailed;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Sends a recorded generation to Pixelforge. Called by the queued job.
 *
 * Safe to run more than once for the same generation: our id is the
 * Idempotency-Key, so Pixelforge hands back the generation it already created
 * instead of making a second one. That covers a worker dying right after
 * Pixelforge accepted the request but before we saved its answer.
 *
 * Failures that should be retried are NOT handled here: they are rethrown for
 * the job to schedule a retry. Everything else ends the generation as failed,
 * which costs the user nothing.
 */
final class ProviderSubmission
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly ImageProvider $provider,
    ) {
    }

    /** @throws RetryableProviderException when trying again later may work */
    public function submit(string $id): void
    {
        $request = $this->generations->findRequest($id) ?? throw GenerationNotFound::withId($id);
        $generation = $this->generations->find($id);

        if ($generation->status !== GenerationStatus::Pending || $generation->providerId !== null) {
            return; // already handed over, nothing to do
        }

        try {
            $remote = $this->provider->createGeneration(
                new CreateGenerationRequest(
                    prompt: $request->prompt,
                    quality: $request->quality,
                    size: $request->size,
                    referenceImages: $request->referenceImages,
                    // Pixelforge echoes this in every webhook, so an event that
                    // arrives before this method finishes can still be matched.
                    metadata: ['generation_id' => $id],
                ),
                idempotencyKey: $id,
            );
        } catch (RetryableProviderException $e) {
            throw $e;
        } catch (ProviderException $e) {
            $this->fail($id, ...$this->explain($e));

            return;
        }

        $this->generations->update($id, function (Generation $generation) use ($remote) {
            $generation->linkProvider($remote->id);
            // False when a webhook already moved it past "queued": then that
            // later state is the truth and we leave it alone.
            $generation->markQueued();
        });
    }

    /** We stopped trying (retries ran out). Nothing is charged for a generation that never ran. */
    public function giveUp(string $id): void
    {
        $this->fail($id, 'provider_unavailable', 'The image service is unavailable right now. You were not charged, please try again.');
    }

    private function fail(string $id, string $code, string $message): void
    {
        $this->generations->update($id, fn (Generation $generation) => $generation->markFailed(
            $code,
            $message,
            Date::now()->toDateTimeImmutable(),
        ));
    }

    /** @return array{0: string, 1: string} error code and a message fit to show the user */
    private function explain(ProviderException $e): array
    {
        return match (true) {
            $e instanceof ContentPolicyViolation => ['content_policy_violation', 'This prompt was rejected by the content policy. You were not charged.'],
            $e instanceof ValidationFailed => ['invalid_request', 'The request was not accepted: '.$e->getMessage().' You were not charged.'],
            $e instanceof DailyLimitExceeded => ['provider_daily_limit', 'The daily generation limit has been reached. Try again tomorrow. You were not charged.'],
            default => $this->unexpected($e),
        };
    }

    /** Authentication, a reused key, an unreadable response: our problem, not the user's. */
    private function unexpected(ProviderException $e): array
    {
        Log::error('Pixelforge rejected a submission in a way we cannot recover from.', [
            'exception' => $e::class,
            'code' => $e->providerCode,
            'http_status' => $e->httpStatus,
            'message' => $e->getMessage(),
        ]);

        return ['provider_error', 'Something went wrong on our side. You were not charged.'];
    }
}

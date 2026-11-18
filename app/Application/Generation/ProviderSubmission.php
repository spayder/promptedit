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
        private readonly ApplyProviderState $apply,
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

        // Normally "queued". Applying whatever Pixelforge reports also covers a
        // replay of an earlier request that has since finished, and leaves a
        // state a webhook already moved on to (e.g. processing) alone.
        $this->generations->update($id, fn (Generation $generation) => $this->apply->handle($generation, $remote));
    }

    /**
     * We stopped trying (retries ran out). Only a generation Pixelforge never took on is
     * ended here, and nothing is charged for it. One that Pixelforge already knows is left
     * for Pixelforge's own report to finish.
     */
    public function giveUp(string $id): void
    {
        $this->fail($id, 'provider_unavailable', 'The image service is unavailable right now. You were not charged, please try again.');
    }

    /**
     * Ends the generation as failed (and free), but only while Pixelforge has not taken it on.
     *
     * Once it has a Pixelforge id, or has moved past "pending" because a webhook got there
     * first, it exists at Pixelforge and may be billed. Telling the user "not charged" then
     * would be a promise we cannot keep, and a failure is final, so a later success would be
     * thrown away. In that case only Pixelforge's report may finish it.
     */
    private function fail(string $id, string $code, string $message): void
    {
        $this->generations->update($id, function (Generation $generation) use ($id, $code, $message) {
            if ($generation->status !== GenerationStatus::Pending || $generation->providerId !== null) {
                Log::warning('Not failing a generation that Pixelforge has already taken on.', [
                    'generation' => $id,
                    'status' => $generation->status->value,
                    'provider_id' => $generation->providerId,
                    'would_have_failed_with' => $code,
                ]);

                return false;
            }

            return $generation->markFailed($code, $message, Date::now()->toDateTimeImmutable());
        });
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

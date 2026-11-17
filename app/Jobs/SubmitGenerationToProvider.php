<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Generation\ProviderSubmission;
use App\Application\Provider\Exceptions\RetryableProviderException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands one generation to Pixelforge, retrying while Pixelforge is busy.
 *
 * Retrying is bounded by time, not by a count: when Pixelforge says "wait 5
 * seconds" and we put the job back, that counts as an attempt, so a plain
 * attempts limit would run out long before the outage ends. Once the window
 * is over the generation is failed, which costs the user nothing.
 */
final class SubmitGenerationToProvider implements ShouldQueue
{
    use Queueable;

    /** How long, from the moment of submitting, we keep trying. */
    public const int RETRY_WINDOW_MINUTES = 10;

    /** Unlimited attempts, so only retryUntil() ends it. */
    public int $tries = 0;

    /** Bugs and outages on our side (not Pixelforge asking us to wait) stop after this many. */
    public int $maxExceptions = 5;

    public function __construct(public readonly string $generationId)
    {
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    /**
     * Pause between retries after an unexpected exception (not for Pixelforge's own "wait" answers).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(ProviderSubmission $submission): void
    {
        try {
            $submission->submit($this->generationId);
        } catch (RetryableProviderException $e) {
            Log::info('Pixelforge asked us to wait, will retry.', [
                'generation' => $this->generationId,
                'reason' => $e::class,
                'seconds' => $e->retryAfterSeconds,
            ]);

            // A little jitter, so jobs released together do not all come back at the same instant.
            $this->release($e->retryAfterSeconds + random_int(0, 2));
        }
    }

    /** Out of time or out of luck: end the generation instead of leaving it pending forever. */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Giving up submitting a generation.', [
            'generation' => $this->generationId,
            'exception' => $exception?->getMessage(),
        ]);

        app(ProviderSubmission::class)->giveUp($this->generationId);
    }
}

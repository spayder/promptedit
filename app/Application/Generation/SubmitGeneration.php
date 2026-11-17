<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\GenerationRepository;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Jobs\SubmitGenerationToProvider;
use Illuminate\Support\Str;

/**
 * Accepts a generation request: records it as pending together with the
 * estimate the user saw, then hands the call to Pixelforge to a queued job.
 *
 * The user gets an answer straight away and never waits on Pixelforge, which
 * can be slow, rate limited or overloaded. The input is expected to be valid
 * already (the form request checks it).
 */
final class SubmitGeneration
{
    public function __construct(
        private readonly QuoteGeneration $quotes,
        private readonly GenerationRepository $generations,
    ) {
    }

    /**
     * @param  list<string>  $referenceImages
     * @param  string|null  $id  the caller's own id, so a repeated submission is recognised; made here if omitted
     * @return string the id of the new generation
     */
    public function handle(
        int $userId,
        string $prompt,
        Quality $quality,
        ImageSize $size,
        array $referenceImages = [],
        ?string $id = null,
    ): string {
        // Our own id doubles as Pixelforge's Idempotency-Key, so sending the
        // same generation twice can never create two.
        $id ??= (string) Str::uuid();

        // The same request again (a double click, a retry): it is already recorded
        // and on its way, so there is nothing more to do.
        if ($this->generations->find($id) !== null) {
            return $id;
        }

        $estimate = $this->quotes->quote($prompt, $quality, $size, count($referenceImages));

        $this->generations->add(new NewGeneration(
            id: $id,
            userId: $userId,
            prompt: $prompt,
            quality: $quality,
            size: $size,
            referenceImages: $referenceImages,
            estimatedCharge: $estimate,
        ));

        SubmitGenerationToProvider::dispatch($id)->afterCommit();

        return $id;
    }
}

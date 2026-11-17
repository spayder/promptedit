<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\GenerationRepository;
use App\Application\Provider\ProviderGeneration;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use Illuminate\Support\Facades\Log;

/**
 * Applies what Pixelforge reports about one of our generations, under a row
 * lock. Whatever learned of the report (a webhook, the poller) goes through
 * here, so they all behave the same and raise the same alarm.
 */
final class SyncGeneration
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly ApplyProviderState $apply,
    ) {
    }

    /**
     * @return GenerationStatus how the generation is recorded afterwards
     *
     * @throws GenerationNotFound
     */
    public function sync(string $ourId, ProviderGeneration $remote): GenerationStatus
    {
        $recordedAs = $this->generations->update($ourId, function (Generation $generation) use ($remote) {
            $this->apply->handle($generation, $remote);

            return $generation->status;
        });

        // We stopped waiting (and told the user they were not charged), but
        // Pixelforge finished it anyway and will bill us. Needs a human.
        if ($remote->status === GenerationStatus::Succeeded && $recordedAs !== GenerationStatus::Succeeded) {
            Log::critical('Pixelforge billed a generation we recorded as not succeeded.', [
                'generation' => $ourId,
                'provider_id' => $remote->id,
                'recorded_as' => $recordedAs->value,
            ]);
        }

        return $recordedAs;
    }
}

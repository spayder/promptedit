<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\WebhookEventStore;
use App\Application\Provider\ProviderGeneration;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use Illuminate\Support\Facades\Log;

/** Applies one stored webhook event to the generation it is about. */
final class ProcessProviderEvent
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly WebhookEventStore $events,
        private readonly ApplyProviderState $apply,
    ) {
    }

    /** @param  ProviderGeneration  $remote  the generation object carried by the event */
    public function handle(string $eventId, ProviderGeneration $remote): void
    {
        // Our id comes back in the metadata, so this works even when the event
        // beats the response to our create call and we do not know Pixelforge's id yet.
        $ourId = $remote->ourGenerationId() ?? $this->generations->findIdByProviderId($remote->id);

        if ($ourId === null) {
            Log::warning('Webhook for a generation we do not know.', ['event' => $eventId, 'provider_id' => $remote->id]);
            $this->events->markProcessed($eventId, null);

            return;
        }

        try {
            $recordedAs = $this->generations->update($ourId, function (Generation $generation) use ($remote) {
                $this->apply->handle($generation, $remote);

                return $generation->status;
            });
        } catch (GenerationNotFound) {
            Log::warning('Webhook for a generation id that is not ours.', ['event' => $eventId, 'generation' => $ourId]);
            $this->events->markProcessed($eventId, null);

            return;
        }

        // We stopped waiting (and told the user they were not charged), but
        // Pixelforge finished it anyway and will bill us. Needs a human.
        if ($remote->status === GenerationStatus::Succeeded && $recordedAs !== GenerationStatus::Succeeded) {
            Log::critical('Pixelforge billed a generation we recorded as not succeeded.', [
                'generation' => $ourId,
                'provider_id' => $remote->id,
                'recorded_as' => $recordedAs->value,
            ]);
        }

        $this->events->markProcessed($eventId, $ourId);
    }
}

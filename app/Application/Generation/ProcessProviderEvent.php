<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\WebhookEventStore;
use App\Application\Provider\ProviderGeneration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Applies one stored webhook event to the generation it is about. */
final class ProcessProviderEvent
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly WebhookEventStore $events,
        private readonly SyncGeneration $sync,
    ) {
    }

    /** @param  ProviderGeneration  $remote  the generation object carried by the event */
    public function handle(string $eventId, ProviderGeneration $remote): void
    {
        // Our id comes back in the metadata, so this works even when the event
        // beats the response to our create call and we do not know Pixelforge's id yet.
        // The metadata is whatever was sent with the request, so only trust it when it
        // looks like one of our ids; anything else is looked up by Pixelforge's id instead.
        $fromMetadata = $remote->ourGenerationId();
        $ourId = $fromMetadata !== null && Str::isUuid($fromMetadata)
            ? $fromMetadata
            : $this->generations->findIdByProviderId($remote->id);

        if ($ourId === null) {
            Log::warning('Webhook for a generation we do not know.', ['event' => $eventId, 'provider_id' => $remote->id]);
            $this->events->markProcessed($eventId, null);

            return;
        }

        try {
            $this->sync->sync($ourId, $remote);
        } catch (GenerationNotFound) {
            Log::warning('Webhook for a generation id that is not ours.', ['event' => $eventId, 'generation' => $ourId]);
            $this->events->markProcessed($eventId, null);

            return;
        }

        $this->events->markProcessed($eventId, $ourId);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\WebhookEventStore;
use App\Jobs\ProcessWebhookEvent;

/**
 * What the webhook endpoint does once a request is known to be genuine: keep
 * the event, and hand the real work to a queued job so we answer well inside
 * Pixelforge's 10 second limit.
 */
final class ReceiveWebhookEvent
{
    public function __construct(private readonly WebhookEventStore $events)
    {
    }

    /**
     * @param  array<string, mixed>  $payload  the whole event body
     * @return bool whether processing was queued (false for a delivery we already finished)
     */
    public function handle(string $eventId, string $type, string $providerGenerationId, array $payload): bool
    {
        if (! $this->events->record($eventId, $type, $providerGenerationId, $payload)) {
            return false;
        }

        ProcessWebhookEvent::dispatch($eventId)->afterCommit();

        return true;
    }
}

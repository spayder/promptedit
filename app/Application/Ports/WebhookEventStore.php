<?php

declare(strict_types=1);

namespace App\Application\Ports;

/**
 * Every webhook event we accepted. Pixelforge delivers at least once, so the
 * event id is what stops an event from being handled twice.
 */
interface WebhookEventStore
{
    /**
     * Remember a delivery. The first time an event id is seen it is stored;
     * after that only the delivery counter goes up.
     *
     * @param  array<string, mixed>  $payload
     * @return bool true when the event still has to be processed: it is new, or an
     *              earlier delivery was stored but never finished
     */
    public function record(string $eventId, string $type, ?string $providerGenerationId, array $payload): bool;

    /** @return array<string, mixed>|null */
    public function payload(string $eventId): ?array;

    /** The event has been dealt with. $generationId is ours, or null when it matched none. */
    public function markProcessed(string $eventId, ?string $generationId): void;
}

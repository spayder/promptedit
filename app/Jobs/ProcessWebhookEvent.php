<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Generation\ProcessProviderEvent;
use App\Application\Ports\WebhookEventStore;
use App\Infrastructure\Pixelforge\ResponseMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a stored webhook event. Safe to run twice for the same event (a
 * duplicate delivery, or a retry): the generation ignores what it has seen.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId)
    {
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(WebhookEventStore $events, ProcessProviderEvent $process): void
    {
        $payload = $events->payload($this->eventId);

        if ($payload === null) {
            return;
        }

        $process->handle($this->eventId, ResponseMapper::generation($payload['data']['object']));
    }
}

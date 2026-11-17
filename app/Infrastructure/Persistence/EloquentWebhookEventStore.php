<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\WebhookEventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;

final class EloquentWebhookEventStore implements WebhookEventStore
{
    private const TABLE = 'webhook_events';

    public function record(string $eventId, string $type, ?string $providerGenerationId, array $payload): bool
    {
        // INSERT ... ON CONFLICT DO NOTHING: the unique event_id decides, in the
        // database, so two simultaneous deliveries cannot both be "first".
        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'event_id' => $eventId,
            'type' => $type,
            'provider_generation_id' => $providerGenerationId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'deliveries' => 1,
            'received_at' => Date::now(),
        ]);

        if ($inserted === 1) {
            return true;
        }

        DB::table(self::TABLE)->where('event_id', $eventId)->increment('deliveries');

        return DB::table(self::TABLE)->where('event_id', $eventId)->whereNull('processed_at')->exists();
    }

    public function payload(string $eventId): ?array
    {
        $json = DB::table(self::TABLE)->where('event_id', $eventId)->value('payload');

        return $json === null ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function markProcessed(string $eventId, ?string $generationId): void
    {
        DB::table(self::TABLE)->where('event_id', $eventId)->update([
            'generation_id' => $generationId,
            'processed_at' => Date::now(),
        ]);
    }
}

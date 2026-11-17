<?php

use App\Jobs\ProcessWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\PixelforgeApi;
use Tests\Fixtures\PixelforgeWebhook;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => PixelforgeWebhook::SECRET]);
    Queue::fake();
});

/** Posts raw bytes with a given signature header, exactly like Pixelforge does. */
function webhookPost(string $rawBody, ?string $signature = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], $server, $rawBody);
}

function signedWebhook(array $event): TestResponse
{
    $raw = json_encode($event);

    return webhookPost($raw, PixelforgeWebhook::signature($raw));
}

function succeededEvent(string $eventId = 'evt_test0001'): array
{
    return PixelforgeWebhook::event('generation.succeeded', PixelforgeApi::generation('succeeded'), $eventId);
}

describe('authenticity', function () {
    it('rejects an unsigned request', function () {
        webhookPost(json_encode(succeededEvent()))->assertUnauthorized();

        expect(DB::table('webhook_events')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('rejects a request signed with the wrong secret', function () {
        $raw = json_encode(succeededEvent());

        webhookPost($raw, PixelforgeWebhook::signature($raw, secret: 'whsec_attacker'))->assertUnauthorized();

        expect(DB::table('webhook_events')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('rejects a replayed request with an old timestamp', function () {
        $raw = json_encode(succeededEvent());

        webhookPost($raw, PixelforgeWebhook::signature($raw, time() - 3600))->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    it('needs no session, cookie or CSRF token', function () {
        signedWebhook(succeededEvent())->assertNoContent();
    });
});

describe('a genuine event', function () {
    it('is stored and queued for processing, and answered quickly with 2xx', function () {
        signedWebhook(succeededEvent('evt_abc'))->assertNoContent();

        $row = DB::table('webhook_events')->first();

        expect($row->event_id)->toBe('evt_abc')
            ->and($row->type)->toBe('generation.succeeded')
            ->and($row->provider_generation_id)->toBe('gen_test0001')
            ->and($row->deliveries)->toBe(1)
            ->and($row->processed_at)->toBeNull()
            ->and(json_decode($row->payload, true)['data']['object']['status'])->toBe('succeeded');

        Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->eventId === 'evt_abc');
    });

    it('is processed once however many times it is delivered', function () {
        signedWebhook(succeededEvent('evt_dup'))->assertNoContent();
        signedWebhook(succeededEvent('evt_dup'))->assertNoContent();
        signedWebhook(succeededEvent('evt_dup'))->assertNoContent();

        expect(DB::table('webhook_events')->count())->toBe(1)
            ->and(DB::table('webhook_events')->value('deliveries'))->toBe(3);
    });

    it('queues processing again for a redelivery whose first attempt never finished', function () {
        signedWebhook(succeededEvent('evt_retry'));
        signedWebhook(succeededEvent('evt_retry'));

        Queue::assertPushed(ProcessWebhookEvent::class, 2);
    });

    it('does not queue anything for a redelivery of an event already processed', function () {
        signedWebhook(succeededEvent('evt_done'));
        DB::table('webhook_events')->where('event_id', 'evt_done')->update(['processed_at' => now()]);

        signedWebhook(succeededEvent('evt_done'))->assertNoContent();

        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    });

    it('keeps different events separate', function () {
        signedWebhook(PixelforgeWebhook::event('generation.processing', PixelforgeApi::generation('processing'), 'evt_1'));
        signedWebhook(succeededEvent('evt_2'));

        expect(DB::table('webhook_events')->pluck('event_id')->all())->toBe(['evt_1', 'evt_2']);
    });
});

describe('things we acknowledge but do not act on', function () {
    it('answers a test ping with 2xx and stores nothing', function () {
        signedWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-17T12:00:00Z', 'data' => ['object' => ['hello' => 'world']]])
            ->assertNoContent();

        expect(DB::table('webhook_events')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('answers an event type we do not know with 2xx, so it is not retried for nothing', function () {
        signedWebhook(PixelforgeWebhook::event('account.something_new', PixelforgeApi::generation('queued')))->assertNoContent();

        Queue::assertNothingPushed();
    });
});

describe('a signed but unusable body', function () {
    it('is rejected with 400', function (string $raw) {
        webhookPost($raw, PixelforgeWebhook::signature($raw))->assertStatus(400);

        expect(DB::table('webhook_events')->count())->toBe(0);
    })->with([
        'not JSON' => ['hello'],
        'JSON but not an object' => ['"hello"'],
        'no event id' => ['{"type":"generation.succeeded","data":{"object":{"id":"gen_1"}}}'],
        'no type' => ['{"id":"evt_1","data":{"object":{"id":"gen_1"}}}'],
        'generation event without a generation' => ['{"id":"evt_1","type":"generation.succeeded","data":{}}'],
        'generation without an id' => ['{"id":"evt_1","type":"generation.succeeded","data":{"object":{"status":"succeeded"}}}'],
    ]);
});

describe('configuration', function () {
    it('refuses everything when no secret is configured, instead of accepting unsigned requests', function () {
        config(['services.pixelforge.webhook_secret' => null]);
        $this->withoutExceptionHandling();

        $raw = json_encode(succeededEvent());

        expect(fn () => webhookPost($raw, PixelforgeWebhook::signature($raw)))->toThrow(RuntimeException::class, 'PIXELFORGE_WEBHOOK_SECRET');
        expect(DB::table('webhook_events')->count())->toBe(0);
    });
});

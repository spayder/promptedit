<?php

use App\Domain\Generation\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\PixelforgeApi;
use Tests\Fixtures\PixelforgeWebhook;

// Here the queue is real (sync), so a delivered webhook is processed end to end.

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => PixelforgeWebhook::SECRET]);
});

/** Delivers one event for one of our generations. */
function deliver(GenerationRecord $generation, string $type, string $status, string $eventId, array $overrides = []): void
{
    $object = PixelforgeApi::generation($status, array_replace([
        // Pixelforge's id for it, if we already know it; otherwise one we have not been told yet.
        'id' => $generation->provider_id ?? 'gen_test0001',
        'metadata' => ['generation_id' => $generation->id],
    ], $overrides));

    $raw = json_encode(PixelforgeWebhook::event($type, $object, $eventId));

    webhookPost($raw, PixelforgeWebhook::signature($raw))->assertNoContent();
}

it('bills a succeeded generation exactly once: provider cost and cost plus 25%', function () {
    $generation = GenerationRecord::factory()->queued()->create();

    // 10 text + 1100 output + 1000 reasoning at 5.00 / 40.00 / 12.50 per 1M tokens
    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1');

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nanos->toBe(56_550_000)
        ->charged_nanos->toBe(70_687_500)
        ->usage_output_tokens->toBe(1_100)
        ->rate_output_nanos->toBe(40_000)
        ->image_url->toContain('gen_test0001.png')
        ->image_url_expires_at->not->toBeNull()
        ->completed_at->not->toBeNull();
});

it('does not charge twice when the same event is delivered twice', function () {
    $generation = GenerationRecord::factory()->queued()->create();

    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1');
    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1');

    expect($generation->fresh())->charged_nanos->toBe(70_687_500)
        ->and(DB::table('webhook_events')->count())->toBe(1)
        ->and(DB::table('webhook_events')->value('deliveries'))->toBe(2)
        ->and(DB::table('webhook_events')->value('processed_at'))->not->toBeNull();
});

it('does not charge twice when the same success arrives under two different event ids', function () {
    $generation = GenerationRecord::factory()->queued()->create();

    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1');
    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_resent_from_dashboard');

    expect($generation->fresh())->charged_nanos->toBe(70_687_500)
        ->and(DB::table('webhook_events')->count())->toBe(2);
});

it('ignores a late "processing" that arrives after "succeeded"', function () {
    $generation = GenerationRecord::factory()->queued()->create();

    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_2');
    deliver($generation, 'generation.processing', 'processing', 'evt_1');

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charged_nanos->toBe(70_687_500);
});

it('handles "succeeded" arriving before "processing" was ever seen', function () {
    $generation = GenerationRecord::factory()->create(); // still pending: even "queued" was never recorded

    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_2');

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_test0001')
        ->charged_nanos->toBe(70_687_500);
});

it('moves a generation forward through processing', function () {
    $generation = GenerationRecord::factory()->queued()->create();

    deliver($generation, 'generation.processing', 'processing', 'evt_1');

    expect($generation->fresh())->status->toBe(GenerationStatus::Processing)->charged_nanos->toBeNull();
});

it('records a failure as free', function () {
    $generation = GenerationRecord::factory()->processing()->create();

    deliver($generation, 'generation.failed', 'failed', 'evt_1');

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('generation_failed')
        ->error_message->toBe('The model could not produce an image.')
        ->provider_cost_nanos->toBe(0)
        ->charged_nanos->toBe(0)
        ->completed_at->not->toBeNull();
});

it('never turns a failure into a charge, nor a success into a failure', function () {
    $failed = GenerationRecord::factory()->processing()->create();
    deliver($failed, 'generation.failed', 'failed', 'evt_1');
    deliver($failed, 'generation.succeeded', 'succeeded', 'evt_2');

    $succeeded = GenerationRecord::factory()->queued()->create();
    deliver($succeeded, 'generation.succeeded', 'succeeded', 'evt_3');
    deliver($succeeded, 'generation.failed', 'failed', 'evt_4');

    expect($failed->fresh())->status->toBe(GenerationStatus::Failed)->charged_nanos->toBe(0)
        ->and($succeeded->fresh())->status->toBe(GenerationStatus::Succeeded)->charged_nanos->toBe(70_687_500);
});

it('bills with the rates frozen on the generation, not today\'s', function () {
    $generation = GenerationRecord::factory()->queued()->create();

    // Pixelforge raised the output price for new generations: this one keeps the old rate it was created with.
    $oldRates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '30.00', 'reasoning_tokens' => '12.50'];
    deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1', ['rates' => $oldRates]);

    // 10*5,000 + 1,100*30,000 + 1,000*12,500 = 45,550,000, + 25% = 56,937,500
    expect($generation->fresh())
        ->rate_output_nanos->toBe(30_000)
        ->provider_cost_nanos->toBe(45_550_000)
        ->charged_nanos->toBe(56_937_500);
});

describe('when the webhook beats the response to our create call', function () {
    it('finds the generation by the metadata we sent and records Pixelforge\'s id', function () {
        $generation = GenerationRecord::factory()->create(); // pending, provider_id still null

        deliver($generation, 'generation.processing', 'processing', 'evt_1');

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Processing)
            ->provider_id->toBe('gen_test0001');
    });
});

describe('events we cannot place', function () {
    it('acknowledges an event for a generation we have never heard of, and marks it processed', function () {
        Log::spy();
        $object = PixelforgeApi::generation('succeeded', ['id' => 'gen_stranger', 'metadata' => []]);
        $raw = json_encode(PixelforgeWebhook::event('generation.succeeded', $object, 'evt_x'));

        webhookPost($raw, PixelforgeWebhook::signature($raw))->assertNoContent();

        expect(DB::table('webhook_events')->first())
            ->generation_id->toBeNull()
            ->processed_at->not->toBeNull()
            ->and(GenerationRecord::query()->count())->toBe(0);

        Log::shouldHaveReceived('warning')->once();
    });

    it('acknowledges an event whose metadata names a generation id that is not ours', function () {
        $object = PixelforgeApi::generation('succeeded', ['id' => 'gen_x', 'metadata' => ['generation_id' => '0192a0a0-0000-7000-8000-00000000beef']]);
        $raw = json_encode(PixelforgeWebhook::event('generation.succeeded', $object, 'evt_x'));

        webhookPost($raw, PixelforgeWebhook::signature($raw))->assertNoContent();

        expect(DB::table('webhook_events')->value('processed_at'))->not->toBeNull();
    });
});

describe('when something is off', function () {
    it('raises an alarm when Pixelforge finished a generation we had already written off', function () {
        Log::spy();
        $generation = GenerationRecord::factory()->failed()->create(['provider_id' => null, 'error_code' => 'provider_unavailable']);

        deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1');

        // We do not silently start charging a user we told "not charged"...
        expect($generation->fresh())->status->toBe(GenerationStatus::Failed)->charged_nanos->toBe(0);
        // ...but a human is told, because Pixelforge will bill us for it.
        Log::shouldHaveReceived('critical')->once();
    });

    it('leaves the event unprocessed when a succeeded report is missing what billing needs', function () {
        $this->withoutExceptionHandling();
        $generation = GenerationRecord::factory()->queued()->create();
        $object = PixelforgeApi::generation('succeeded', ['id' => $generation->provider_id, 'usage' => null, 'metadata' => ['generation_id' => $generation->id]]);
        $raw = json_encode(PixelforgeWebhook::event('generation.succeeded', $object, 'evt_1'));

        // The endpoint still answers 2xx: it only stores and queues. The job is what fails.
        expect(fn () => webhookPost($raw, PixelforgeWebhook::signature($raw)))->toThrow(App\Application\Provider\Exceptions\UnexpectedResponse::class);

        expect($generation->fresh())->status->toBe(GenerationStatus::Queued)->charged_nanos->toBeNull()
            ->and(DB::table('webhook_events')->value('processed_at'))->toBeNull();
    });

    it('can process a stored event again later', function () {
        $generation = GenerationRecord::factory()->queued()->create();
        deliver($generation, 'generation.succeeded', 'succeeded', 'evt_1');

        // e.g. a retry of the job after a crash: nothing changes, nothing is charged again.
        (new ProcessWebhookEvent('evt_1'))->handle(app(App\Application\Ports\WebhookEventStore::class), app(App\Application\Generation\ProcessProviderEvent::class));

        expect($generation->fresh())->charged_nanos->toBe(70_687_500);
    });
});

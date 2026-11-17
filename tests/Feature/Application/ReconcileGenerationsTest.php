<?php

use App\Application\Generation\ReconcileGenerations;
use App\Application\Provider\Exceptions\RateLimited;
use App\Application\Provider\Exceptions\UnexpectedResponse;
use App\Domain\Generation\GenerationStatus;
use App\Infrastructure\Pixelforge\ResponseMapper;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FakeImageProvider;
use Tests\Fixtures\PixelforgeApi;

const IDLE = 120;
const PENDING = 720;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $this->provider = new FakeImageProvider;
    $this->app->instance(App\Application\Ports\ImageProvider::class, $this->provider);
});

function reconcile(int $limit = 20): App\Application\Generation\ReconcileResult
{
    return app(ReconcileGenerations::class)->handle(IDLE, PENDING, $limit);
}

/** A generation that has been sitting in $status for $minutes. */
function stale(string $state, int $minutes, array $attributes = []): GenerationRecord
{
    return GenerationRecord::factory()->{$state}()->create(array_replace([
        'created_at' => now()->subMinutes($minutes),
        'updated_at' => now()->subMinutes($minutes),
    ], $attributes));
}

/** What Pixelforge would say about it now. */
function remoteSays(GenerationRecord $generation, string $status): void
{
    test()->provider->remote[$generation->provider_id] = ResponseMapper::generation(
        PixelforgeApi::generation($status, ['id' => $generation->provider_id, 'metadata' => ['generation_id' => $generation->id]]),
    );
}

describe('a generation whose webhook never arrived', function () {
    it('is brought up to date and billed once', function () {
        $generation = stale('queued', minutes: 5);
        remoteSays($generation, 'succeeded');

        $result = reconcile();

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Succeeded)
            ->provider_cost_nanos->toBe(56_550_000)
            ->charged_nanos->toBe(70_687_500)
            ->and($result->checked)->toBe(1)
            ->and($result->updated)->toBe(1);
    });

    it('does not bill again when run a second time', function () {
        $generation = stale('queued', minutes: 5);
        remoteSays($generation, 'succeeded');

        reconcile();
        $again = reconcile();

        expect($generation->fresh())->charged_nanos->toBe(70_687_500)
            ->and($again->checked)->toBe(0); // it is finished, so no longer overdue
    });

    it('records a failure Pixelforge reports as free', function () {
        $generation = stale('processing', minutes: 5);
        remoteSays($generation, 'failed');

        reconcile();

        expect($generation->fresh())->status->toBe(GenerationStatus::Failed)->charged_nanos->toBe(0);
    });

    it('moves a queued generation to processing', function () {
        $generation = stale('queued', minutes: 5);
        remoteSays($generation, 'processing');

        $result = reconcile();

        expect($generation->fresh())->status->toBe(GenerationStatus::Processing)
            ->and($result->updated)->toBe(1);
    });

    it('leaves a generation that is genuinely still processing alone', function () {
        $generation = stale('processing', minutes: 5);
        remoteSays($generation, 'processing');

        $result = reconcile();

        expect($generation->fresh())->status->toBe(GenerationStatus::Processing)
            ->and($result->checked)->toBe(1)
            ->and($result->updated)->toBe(0);
    });

    it('does not ask about generations that changed recently', function () {
        $fresh = stale('queued', minutes: 1);

        $result = reconcile();

        expect($result->checked)->toBe(0)
            ->and($this->provider->fetched)->toBe([])
            ->and($fresh->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('does not ask about finished generations', function () {
        stale('succeeded', minutes: 60);
        stale('failed', minutes: 60);

        expect(reconcile()->checked)->toBe(0);
    });
});

describe('a generation that never reached Pixelforge', function () {
    it('has its submission queued again', function () {
        $pending = stale('pending', minutes: 13);

        $result = reconcile();

        Queue::assertPushed(SubmitGenerationToProvider::class, fn ($job) => $job->generationId === $pending->id);
        expect($result->resubmitted)->toBe(1)
            ->and($this->provider->fetched)->toBe([]); // there is nothing to look up yet
    });

    it('is left alone while its own job may still be retrying', function () {
        stale('pending', minutes: 9);

        expect(reconcile()->resubmitted)->toBe(0);
        Queue::assertNothingPushed();
    });

    it('is not queued again on every run', function () {
        stale('pending', minutes: 13);

        reconcile();
        reconcile();
        reconcile();

        Queue::assertPushed(SubmitGenerationToProvider::class, 1);
    });
});

describe('when looking things up goes wrong', function () {
    it('stops for this run when Pixelforge asks us to slow down, and tries again next time', function () {
        $first = stale('queued', minutes: 10);
        $second = stale('queued', minutes: 9);
        $this->provider->getFailures[$first->provider_id] = new RateLimited('slow down', 10);
        remoteSays($second, 'succeeded');

        $result = reconcile();

        expect($this->provider->fetched)->toBe([$first->provider_id]) // did not hammer on
            ->and($second->fresh()->status)->toBe(GenerationStatus::Queued)
            ->and($result->checked)->toBe(0);

        // Next run, Pixelforge is fine again.
        unset($this->provider->getFailures[$first->provider_id]);
        remoteSays($first, 'succeeded');
        reconcile();

        expect($first->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($second->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('carries on past a generation Pixelforge does not know', function () {
        Log::spy();
        $unknown = stale('queued', minutes: 10); // no answer registered: Pixelforge says 404
        $known = stale('queued', minutes: 9);
        remoteSays($known, 'succeeded');

        $result = reconcile();

        expect($known->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($unknown->fresh()->status)->toBe(GenerationStatus::Queued)
            ->and($result->failed)->toBe(1)
            ->and($result->checked)->toBe(1);

        Log::shouldHaveReceived('warning')->once();
    });

    it('carries on past a lookup that cannot be understood', function () {
        $odd = stale('queued', minutes: 10);
        $good = stale('queued', minutes: 9);
        $this->provider->getFailures[$odd->provider_id] = new UnexpectedResponse('garbled');
        remoteSays($good, 'succeeded');

        $result = reconcile();

        expect($good->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($result->failed)->toBe(1);
    });

    it('looks at no more than the limit, oldest first', function () {
        $oldest = stale('queued', minutes: 30);
        $middle = stale('queued', minutes: 20);
        $newest = stale('queued', minutes: 10);
        foreach ([$oldest, $middle, $newest] as $generation) {
            remoteSays($generation, 'processing');
        }

        reconcile(limit: 2);

        expect($this->provider->fetched)->toBe([$oldest->provider_id, $middle->provider_id]);
    });
});

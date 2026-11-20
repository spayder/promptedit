<?php

use App\Application\Ports\ImageProvider;
use App\Domain\Generation\GenerationStatus;
use App\Infrastructure\Pixelforge\ResponseMapper;
use App\Models\Generation as GenerationRecord;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FakeImageProvider;
use Tests\Fixtures\PixelforgeApi;

it('catches up a stuck generation and reports what it did', function () {
    Queue::fake();
    $provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $provider);

    $stuck = GenerationRecord::factory()->queued()->create(['updated_at' => now()->subMinutes(10)]);
    $provider->remote[$stuck->provider_id] = ResponseMapper::generation(
        PixelforgeApi::generation('succeeded', ['id' => $stuck->provider_id, 'metadata' => ['generation_id' => $stuck->id]]),
    );

    $this->artisan('generations:reconcile')
        ->expectsOutput('Checked 1, updated 1, resubmitted 0, failed lookups 0.')
        ->assertSuccessful();

    expect($stuck->fresh())->status->toBe(GenerationStatus::Succeeded)->charged_nanos->toBe(70_687_500);
});

it('does nothing, quietly, when there is nothing overdue', function () {
    $this->app->instance(ImageProvider::class, new FakeImageProvider);

    $this->artisan('generations:reconcile')
        ->expectsOutput('Checked 0, updated 0, resubmitted 0, failed lookups 0.')
        ->assertSuccessful();
});

it('is scheduled every five minutes without overlapping itself', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'generations:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

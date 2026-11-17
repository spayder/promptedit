<?php

use App\Application\Generation\SubmitGeneration;
use App\Application\Ports\ImageProvider;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FakeImageProvider;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
});

it('records a pending generation with the estimate and queues the call to Pixelforge', function () {
    $user = User::factory()->create();

    $id = app(SubmitGeneration::class)->handle(
        userId: $user->id,
        prompt: 'A lighthouse at dusk',
        quality: Quality::Low,
        size: ImageSize::Square,
        referenceImages: ['https://example.com/ref.png'],
    );

    $record = GenerationRecord::query()->findOrFail($id);

    expect($record->user_id)->toBe($user->id)
        ->and($record->status)->toBe(GenerationStatus::Pending)
        ->and($record->prompt)->toBe('A lighthouse at dusk')
        ->and($record->reference_images)->toBe(['https://example.com/ref.png'])
        ->and($record->provider_id)->toBeNull()
        ->and($record->charged_nanos)->toBeNull()
        ->and($record->estimated_charge_min_nanos)->toBeInt()->toBeGreaterThan(0)
        ->and($record->estimated_charge_max_nanos)->toBeGreaterThan($record->estimated_charge_min_nanos);

    Queue::assertPushed(SubmitGenerationToProvider::class, fn ($job) => $job->generationId === $id);
});

it('stores the same estimate the quote shows', function () {
    $user = User::factory()->create();

    $id = app(SubmitGeneration::class)->handle($user->id, '', Quality::Low, ImageSize::Square);

    $record = GenerationRecord::query()->findOrFail($id);

    expect($record->estimated_charge_min_nanos)->toBe(14_000_000)
        ->and($record->estimated_charge_max_nanos)->toBe(21_812_500);
});

it('does not talk to Pixelforge itself, so the user never waits on it', function () {
    $user = User::factory()->create();

    app(SubmitGeneration::class)->handle($user->id, 'hello', Quality::Low, ImageSize::Square);

    expect($this->provider->created)->toBe([]);
});

it('gives every submission its own id', function () {
    $user = User::factory()->create();
    $submit = app(SubmitGeneration::class);

    $a = $submit->handle($user->id, 'same prompt', Quality::Low, ImageSize::Square);
    $b = $submit->handle($user->id, 'same prompt', Quality::Low, ImageSize::Square);

    expect($a)->not->toBe($b)
        ->and(GenerationRecord::query()->count())->toBe(2);
});

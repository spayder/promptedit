<?php

use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\Overloaded;
use App\Application\Provider\Exceptions\RateLimited;
use App\Domain\Generation\GenerationStatus;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation as GenerationRecord;
use Tests\Fixtures\FakeImageProvider;

beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
});

it('submits the generation and finishes', function () {
    $pending = GenerationRecord::factory()->create();

    $job = (new SubmitGenerationToProvider($pending->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($pending->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('puts the job back for as long as Pixelforge asked, plus a little jitter', function (Throwable $failure, int $wait) {
    $pending = GenerationRecord::factory()->create();
    $this->provider->createFailure = $failure;

    $job = (new SubmitGenerationToProvider($pending->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual($wait)->toBeLessThanOrEqual($wait + 2)
        ->and($pending->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'overloaded' => [fn () => new Overloaded('busy', 2), 2],
    'rate limited' => [fn () => new RateLimited('slow down', 30), 30],
]);

it('keeps retrying for a time window instead of a fixed number of attempts', function () {
    $job = new SubmitGenerationToProvider('any');

    expect($job->tries)->toBe(0)
        ->and($job->retryUntil()->getTimestamp())->toBeBetween(
            now()->addMinutes(9)->getTimestamp(),
            now()->addMinutes(11)->getTimestamp(),
        );
});

it('fails the generation, free of charge, when it runs out of retries', function () {
    $pending = GenerationRecord::factory()->create();

    (new SubmitGenerationToProvider($pending->id))->failed(new RuntimeException('too many attempts'));

    expect($pending->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('provider_unavailable')
        ->charged_nanos->toBe(0);
});

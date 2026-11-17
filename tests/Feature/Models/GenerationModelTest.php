<?php

use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('persists a pending generation and casts its columns', function () {
    $generation = Generation::factory()->create([
        'size' => ImageSize::Landscape,
        'quality' => Quality::High,
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);

    $fresh = Generation::findOrFail($generation->id);

    expect(Str::isUuid($fresh->id))->toBeTrue()
        ->and($fresh->status)->toBe(GenerationStatus::Pending)
        ->and($fresh->size)->toBe(ImageSize::Landscape)
        ->and($fresh->quality)->toBe(Quality::High)
        ->and($fresh->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
        ->and($fresh->provider_id)->toBeNull()
        ->and($fresh->charged_nanos)->toBeNull();
});

it('stores money as exact integer nanodollars', function () {
    $generation = Generation::factory()->succeeded()->create([
        'provider_cost_nanos' => 373_875_000,
        'charged_nanos' => 467_343_750,
    ]);

    $fresh = Generation::findOrFail($generation->id);

    expect($fresh->provider_cost_nanos)->toBeInt()->toBe(373_875_000)
        ->and($fresh->charged_nanos)->toBeInt()->toBe(467_343_750);
});

it('casts completion timestamps to immutable dates', function () {
    $fresh = Generation::findOrFail(Generation::factory()->succeeded()->create()->id);

    expect($fresh->completed_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($fresh->image_url_expires_at)->toBeInstanceOf(DateTimeImmutable::class);
});

it('belongs to a user and is listed on that user', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();

    expect($generation->user->is($user))->toBeTrue()
        ->and($user->generations()->pluck('id')->all())->toBe([$generation->id]);
});

it('builds valid rows for every lifecycle state', function () {
    foreach (['queued', 'processing', 'succeeded', 'failed'] as $state) {
        $generation = Generation::factory()->{$state}()->create();

        expect($generation->fresh()->status->value)->toBe($state);
    }
});

it('does not let two generations share a provider id', function () {
    Generation::factory()->queued()->create(['provider_id' => 'gen_same']);

    Generation::factory()->queued()->create(['provider_id' => 'gen_same']);
})->throws(QueryException::class);

describe('database safety nets', function () {
    it('refuses to charge a failed generation', function () {
        Generation::factory()->failed()->create(['charged_nanos' => 1]);
    })->throws(QueryException::class, 'generations_failed_is_free');

    it('refuses a charge on a generation that has not finished', function () {
        Generation::factory()->processing()->create(['charged_nanos' => 5, 'provider_cost_nanos' => 4]);
    })->throws(QueryException::class, 'generations_amounts_set_when_finished');

    it('refuses a finished generation without amounts', function () {
        Generation::factory()->succeeded()->create(['charged_nanos' => null, 'provider_cost_nanos' => null]);
    })->throws(QueryException::class, 'generations_amounts_set_when_finished');
});

<?php

use App\Application\Generation\GenerationNotFound;
use App\Application\Generation\NewGeneration;
use App\Application\Ports\GenerationRepository;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Domain\Generation\TokenUsage;
use App\Domain\Money;
use App\Domain\Pricing\Biller;
use App\Domain\Pricing\CostCalculator;
use App\Domain\Pricing\CostEstimate;
use App\Domain\Pricing\MarkupPolicy;
use App\Infrastructure\Persistence\EloquentGenerationRepository;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PixelforgePricing;

function repository(): GenerationRepository
{
    return app(GenerationRepository::class);
}

function succeedWith(Generation $generation, ?TokenUsage $usage = null): bool
{
    return $generation->markSucceeded(
        biller: new Biller(new CostCalculator, MarkupPolicy::percent(25)),
        usage: $usage ?? new TokenUsage(115, 1_530, 6_450, 8_000), // docs worked example
        rates: PixelforgePricing::rates(),
        imageUrl: 'https://img.test/1.png',
        imageExpiresAt: new DateTimeImmutable('2026-11-18T12:00:00Z'),
        completedAt: new DateTimeImmutable('2026-11-17T12:00:00Z'),
    );
}

function newGeneration(User $user, string $id = '0192a0a0-0000-7000-8000-000000000001'): NewGeneration
{
    return new NewGeneration(
        id: $id,
        userId: $user->id,
        prompt: 'A lighthouse at dusk',
        quality: Quality::High,
        size: ImageSize::Landscape,
        referenceImages: ['https://example.com/ref.png'],
        estimatedCharge: new CostEstimate(Money::fromDecimal('0.357781250'), Money::fromDecimal('0.467343750')),
    );
}

it('is bound to the Eloquent adapter', function () {
    expect(repository())->toBeInstanceOf(EloquentGenerationRepository::class);
});

describe('add and find', function () {
    it('records a pending generation with the request and the estimate shown', function () {
        $user = User::factory()->create();

        $generation = repository()->add(newGeneration($user));

        $row = GenerationRecord::findOrFail($generation->id);

        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($row->user_id)->toBe($user->id)
            ->and($row->status)->toBe(GenerationStatus::Pending)
            ->and($row->prompt)->toBe('A lighthouse at dusk')
            ->and($row->quality)->toBe(Quality::High)
            ->and($row->size)->toBe(ImageSize::Landscape)
            ->and($row->reference_images)->toBe(['https://example.com/ref.png'])
            ->and($row->estimated_charge_min_nanos)->toBe(357_781_250)
            ->and($row->estimated_charge_max_nanos)->toBe(467_343_750)
            ->and($row->charged_nanos)->toBeNull();
    });

    it('finds nothing for an unknown id', function () {
        expect(repository()->find('0192a0a0-0000-7000-8000-0000000000ff'))->toBeNull();
    });

    it('rebuilds a finished generation exactly, money included', function () {
        $row = GenerationRecord::factory()->succeeded()->create();

        $generation = repository()->find($row->id);

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->providerId)->toBe($row->provider_id)
            ->and($generation->providerCost->toNanos())->toBe(56_550_000)
            ->and($generation->charge->toNanos())->toBe(70_687_500)
            ->and($generation->usage)->toEqual(new TokenUsage(10, 0, 1_100, 1_000))
            ->and($generation->rates->outputNanos)->toBe(40_000)
            ->and($generation->imageUrl)->toBe($row->image_url);
    });

    it('rebuilds a failed generation as free', function () {
        $row = GenerationRecord::factory()->failed()->create();

        $generation = repository()->find($row->id);

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charge->isZero())->toBeTrue()
            ->and($generation->errorCode)->toBe('generation_failed')
            ->and($generation->usage)->toBeNull()
            ->and($generation->rates)->toBeNull();
    });

    it('maps a Pixelforge id back to our id', function () {
        $row = GenerationRecord::factory()->queued()->create(['provider_id' => 'gen_abc123']);

        expect(repository()->findIdByProviderId('gen_abc123'))->toBe($row->id)
            ->and(repository()->findIdByProviderId('gen_unknown'))->toBeNull();
    });
});

describe('update', function () {
    it('persists the exact provider cost and charge when a generation succeeds', function () {
        $row = GenerationRecord::factory()->processing()->create();

        $changed = repository()->update($row->id, fn (Generation $g) => succeedWith($g));

        $row->refresh();

        expect($changed)->toBeTrue()
            ->and($row->status)->toBe(GenerationStatus::Succeeded)
            ->and($row->provider_cost_nanos)->toBe(373_875_000)
            ->and($row->charged_nanos)->toBe(467_343_750)
            ->and($row->usage_input_text_tokens)->toBe(115)
            ->and($row->usage_input_image_tokens)->toBe(1_530)
            ->and($row->usage_output_tokens)->toBe(6_450)
            ->and($row->usage_reasoning_tokens)->toBe(8_000)
            ->and($row->rate_output_nanos)->toBe(40_000)
            ->and($row->image_url)->toBe('https://img.test/1.png')
            ->and($row->completed_at->toIso8601String())->toBe('2026-11-17T12:00:00+00:00')
            ->and($row->image_url_expires_at->toIso8601String())->toBe('2026-11-18T12:00:00+00:00');
    });

    it('records a failure as zero cost and zero charge', function () {
        $row = GenerationRecord::factory()->processing()->create();

        repository()->update($row->id, fn (Generation $g) => $g->markFailed('internal_error', 'Boom', new DateTimeImmutable('2026-11-17T12:00:00Z')));

        $row->refresh();

        expect($row->status)->toBe(GenerationStatus::Failed)
            ->and($row->provider_cost_nanos)->toBe(0)
            ->and($row->charged_nanos)->toBe(0)
            ->and($row->error_code)->toBe('internal_error');
    });

    it('bills once even when the same success is applied twice', function () {
        $row = GenerationRecord::factory()->processing()->create();

        $first = repository()->update($row->id, fn (Generation $g) => succeedWith($g));
        $second = repository()->update($row->id, fn (Generation $g) => succeedWith($g, new TokenUsage(1, 0, 1, 0)));

        $row->refresh();

        expect($first)->toBeTrue()
            ->and($second)->toBeFalse()
            ->and($row->charged_nanos)->toBe(467_343_750)
            ->and($row->usage_output_tokens)->toBe(6_450);
    });

    it('ignores a late processing event after success', function () {
        $row = GenerationRecord::factory()->processing()->create();

        repository()->update($row->id, fn (Generation $g) => succeedWith($g));
        $late = repository()->update($row->id, fn (Generation $g) => $g->markProcessing());

        expect($late)->toBeFalse()
            ->and($row->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($row->fresh()->charged_nanos)->toBe(467_343_750);
    });

    it('lets a success arrive before the create response, then links the provider id', function () {
        $row = GenerationRecord::factory()->create(); // still pending, no provider id

        repository()->update($row->id, function (Generation $g) {
            $g->linkProvider('gen_early');
            succeedWith($g);
        });

        $row->refresh();

        expect($row->provider_id)->toBe('gen_early')
            ->and($row->status)->toBe(GenerationStatus::Succeeded);
    });

    it('saves nothing when the change is a no-op', function () {
        $row = GenerationRecord::factory()->succeeded()->create();
        $before = $row->fresh()->updated_at;
        $this->travel(5)->minutes();

        repository()->update($row->id, fn (Generation $g) => $g->markProcessing());

        expect($row->fresh()->updated_at->equalTo($before))->toBeTrue();
    });

    it('saves nothing when the change throws', function () {
        $row = GenerationRecord::factory()->processing()->create();

        expect(fn () => repository()->update($row->id, function (Generation $g) {
            succeedWith($g);
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class, 'boom');

        $row->refresh();

        expect($row->status)->toBe(GenerationStatus::Processing)
            ->and($row->charged_nanos)->toBeNull();
    });

    it('refuses to re-link a generation to another Pixelforge id and leaves it untouched', function () {
        $row = GenerationRecord::factory()->queued()->create(['provider_id' => 'gen_one']);

        expect(fn () => repository()->update($row->id, fn (Generation $g) => $g->linkProvider('gen_two')))
            ->toThrow(DomainException::class);

        expect($row->fresh()->provider_id)->toBe('gen_one');
    });

    it('throws for an unknown generation', function () {
        repository()->update('0192a0a0-0000-7000-8000-0000000000ff', fn (Generation $g) => true);
    })->throws(GenerationNotFound::class);

    it('locks the row for the duration of the change', function () {
        $row = GenerationRecord::factory()->processing()->create();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        repository()->update($row->id, fn (Generation $g) => $g->markProcessing());

        $locking = array_filter($queries, fn ($sql) => str_contains($sql, 'from "generations"') && str_contains($sql, 'for update'));

        expect($locking)->not->toBeEmpty();
    });

    it('runs the change inside a transaction', function () {
        $row = GenerationRecord::factory()->processing()->create();
        $level = null;

        repository()->update($row->id, function (Generation $g) use (&$level) {
            $level = DB::transactionLevel();
        });

        // RefreshDatabase already holds one transaction, so ours is nested (savepoint).
        expect($level)->toBeGreaterThanOrEqual(2);
    });
});

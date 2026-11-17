<?php

use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\TokenUsage;
use App\Domain\Pricing\Biller;
use App\Domain\Pricing\CostCalculator;
use App\Domain\Pricing\MarkupPolicy;
use Tests\Fixtures\PixelforgePricing;

function biller25(): Biller
{
    return new Biller(new CostCalculator, MarkupPolicy::percent(25));
}

/** The docs' worked example: provider cost 0.373875000, charge 0.467343750. */
function worstCaseUsage(): TokenUsage
{
    return new TokenUsage(inputTextTokens: 115, inputImageTokens: 1_530, outputTokens: 6_450, reasoningTokens: 8_000);
}

function succeed(Generation $generation, ?TokenUsage $usage = null, string $url = 'https://img/1.png'): bool
{
    return $generation->markSucceeded(
        biller: biller25(),
        usage: $usage ?? worstCaseUsage(),
        rates: PixelforgePricing::rates(),
        imageUrl: $url,
        imageExpiresAt: new DateTimeImmutable('2026-11-18T12:00:00Z'),
        completedAt: new DateTimeImmutable('2026-11-17T12:00:00Z'),
    );
}

function fail(Generation $generation, string $code = 'generation_failed'): bool
{
    return $generation->markFailed($code, 'The model could not produce an image.', new DateTimeImmutable('2026-11-17T12:00:00Z'));
}

describe('a new generation', function () {
    it('starts pending, unbilled and without a provider id', function () {
        $generation = Generation::pending('g-1');

        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->providerId)->toBeNull()
            ->and($generation->providerCost)->toBeNull()
            ->and($generation->charge)->toBeNull()
            ->and($generation->usage)->toBeNull();
    });
});

describe('provider id', function () {
    it('can be linked once and repeated harmlessly', function () {
        $generation = Generation::pending('g-1');

        $generation->linkProvider('gen_abc');
        $generation->linkProvider('gen_abc');

        expect($generation->providerId)->toBe('gen_abc');
    });

    it('refuses to be linked to a different provider generation', function () {
        $generation = Generation::pending('g-1');
        $generation->linkProvider('gen_abc');

        $generation->linkProvider('gen_other');
    })->throws(DomainException::class);
});

describe('progressing', function () {
    it('moves pending -> queued -> processing', function () {
        $generation = Generation::pending('g-1');

        expect($generation->markQueued())->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->markProcessing())->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Processing);
    });

    it('ignores repeated and backwards progress without changing anything', function () {
        $generation = Generation::pending('g-1');
        $generation->markProcessing();

        expect($generation->markProcessing())->toBeFalse()
            ->and($generation->markQueued())->toBeFalse()
            ->and($generation->status)->toBe(GenerationStatus::Processing);
    });
});

describe('succeeding', function () {
    it('records usage, rates, exact provider cost, marked-up charge and the image', function () {
        $generation = Generation::pending('g-1');
        $generation->markProcessing();

        expect(succeed($generation))->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage)->toEqual(worstCaseUsage())
            ->and($generation->rates)->toEqual(PixelforgePricing::rates())
            ->and($generation->providerCost->toDecimal())->toBe('0.373875000')
            ->and($generation->charge->toDecimal())->toBe('0.467343750')
            ->and($generation->imageUrl)->toBe('https://img/1.png')
            ->and($generation->imageExpiresAt)->toEqual(new DateTimeImmutable('2026-11-18T12:00:00Z'))
            ->and($generation->completedAt)->toEqual(new DateTimeImmutable('2026-11-17T12:00:00Z'));
    });

    it('can succeed straight from pending when the webhook beats the create response', function () {
        $generation = Generation::pending('g-1');

        expect(succeed($generation))->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Succeeded);
    });

    it('bills only once: a duplicate success is ignored even if its payload differs', function () {
        $generation = Generation::pending('g-1');
        succeed($generation);

        $different = new TokenUsage(inputTextTokens: 1, inputImageTokens: 0, outputTokens: 1, reasoningTokens: 0);

        expect(succeed($generation, $different, 'https://img/other.png'))->toBeFalse()
            ->and($generation->providerCost->toDecimal())->toBe('0.373875000')
            ->and($generation->charge->toDecimal())->toBe('0.467343750')
            ->and($generation->usage)->toEqual(worstCaseUsage())
            ->and($generation->imageUrl)->toBe('https://img/1.png');
    });

    it('ignores a late processing or queued event after success', function () {
        $generation = Generation::pending('g-1');
        succeed($generation);

        expect($generation->markProcessing())->toBeFalse()
            ->and($generation->markQueued())->toBeFalse()
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charge->toDecimal())->toBe('0.467343750');
    });
});

describe('failing', function () {
    it('costs nothing and records the error', function () {
        $generation = Generation::pending('g-1');
        $generation->markProcessing();

        expect(fail($generation, 'internal_error'))->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->providerCost->isZero())->toBeTrue()
            ->and($generation->charge->isZero())->toBeTrue()
            ->and($generation->errorCode)->toBe('internal_error')
            ->and($generation->errorMessage)->toBe('The model could not produce an image.')
            ->and($generation->usage)->toBeNull()
            ->and($generation->imageUrl)->toBeNull();
    });

    it('can fail before Pixelforge ever accepted it', function () {
        $generation = Generation::pending('g-1');

        expect(fail($generation, 'content_policy_violation'))->toBeTrue()
            ->and($generation->charge->isZero())->toBeTrue();
    });

    it('ignores a duplicate failure', function () {
        $generation = Generation::pending('g-1');
        fail($generation);

        expect(fail($generation, 'internal_error'))->toBeFalse()
            ->and($generation->errorCode)->toBe('generation_failed');
    });
});

describe('final states are final', function () {
    it('never turns a success into a failure or refunds it', function () {
        $generation = Generation::pending('g-1');
        succeed($generation);

        expect(fail($generation))->toBeFalse()
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charge->toDecimal())->toBe('0.467343750')
            ->and($generation->errorCode)->toBeNull();
    });

    it('never turns a failure into a billable success', function () {
        $generation = Generation::pending('g-1');
        fail($generation);

        expect(succeed($generation))->toBeFalse()
            ->and($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charge->isZero())->toBeTrue()
            ->and($generation->imageUrl)->toBeNull();
    });
});

describe('restoring from storage', function () {
    it('round-trips a billed generation without recomputing anything', function () {
        $original = Generation::pending('g-1');
        $original->linkProvider('gen_abc');
        succeed($original);

        $restored = Generation::restore(
            id: $original->id,
            status: $original->status,
            providerId: $original->providerId,
            usage: $original->usage,
            rates: $original->rates,
            providerCost: $original->providerCost,
            charge: $original->charge,
            imageUrl: $original->imageUrl,
            imageExpiresAt: $original->imageExpiresAt,
            errorCode: $original->errorCode,
            errorMessage: $original->errorMessage,
            completedAt: $original->completedAt,
        );

        expect($restored)->toEqual($original)
            // and it is still protected against a duplicate event after reload
            ->and(succeed($restored))->toBeFalse();
    });
});

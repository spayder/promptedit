<?php

use App\Application\Provider\Exceptions\UnexpectedResponse;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\TokenUsage;
use App\Infrastructure\Pixelforge\ResponseMapper;
use Tests\Fixtures\PixelforgeApi;
use Tests\Fixtures\PixelforgePricing;

describe('generation', function () {
    it('reads a queued generation', function () {
        $generation = ResponseMapper::generation(PixelforgeApi::generation('queued'));

        expect($generation->id)->toBe('gen_test0001')
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->usage)->toBeNull()
            ->and($generation->imageUrl)->toBeNull()
            ->and($generation->completedAt)->toBeNull()
            ->and($generation->createdAt->format('c'))->toBe('2026-11-17T12:00:00+00:00');
    });

    it('reads the rates frozen on the generation as nanodollars per token', function () {
        $rates = ResponseMapper::generation(PixelforgeApi::generation('queued'))->rates;

        expect($rates)->toEqual(PixelforgePricing::rates());
    });

    it('reads a succeeded generation with usage, image and completion time', function () {
        $generation = ResponseMapper::generation(PixelforgeApi::generation('succeeded'));

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage)->toEqual(new TokenUsage(10, 0, 1_100, 1_000))
            ->and($generation->imageUrl)->toContain('gen_test0001.png')
            ->and($generation->imageExpiresAt->format('c'))->toBe('2026-11-18T12:00:20+00:00')
            ->and($generation->completedAt->format('c'))->toBe('2026-11-17T12:00:20+00:00');
    });

    it('reads a failed generation with its error', function () {
        $generation = ResponseMapper::generation(PixelforgeApi::generation('failed'));

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->errorCode)->toBe('generation_failed')
            ->and($generation->errorMessage)->toBe('The model could not produce an image.')
            ->and($generation->usage)->toBeNull();
    });

    it('exposes our generation id from the echoed metadata', function () {
        $generation = ResponseMapper::generation(PixelforgeApi::generation());

        expect($generation->ourGenerationId())->toBe('0192a0a0-0000-7000-8000-000000000001');

        $without = ResponseMapper::generation(PixelforgeApi::generation('queued', ['metadata' => []]));

        expect($without->ourGenerationId())->toBeNull();
    });

    it('rejects a status that is not one of the provider\'s four', function (string $status) {
        ResponseMapper::generation(PixelforgeApi::generation('queued', ['status' => $status]));
    })->with(['pending is ours, not theirs' => ['pending'], 'garbage' => ['done'], 'empty' => ['']])
        ->throws(UnexpectedResponse::class);

    it('rejects an object with missing or malformed fields', function (array $broken) {
        ResponseMapper::generation(array_replace(PixelforgeApi::generation('succeeded'), $broken));
    })->with([
        'no id' => [['id' => null]],
        'no created_at' => [['created_at' => null]],
        'bad date' => [['created_at' => 'not a date']],
        'usage with a string count' => [['usage' => ['input_text_tokens' => '10', 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0]]],
        'usage missing a field' => [['usage' => ['input_text_tokens' => 10]]],
        'negative usage' => [['usage' => ['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0]]],
        'rate that needs rounding' => [['rates' => ['input_text_tokens' => '5.0005', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']]],
    ])->throws(UnexpectedResponse::class);
});

describe('pricing', function () {
    it('reads the documented pricing response', function () {
        $pricing = ResponseMapper::pricing(PixelforgePricing::response());

        expect($pricing->rates)->toEqual(PixelforgePricing::rates())
            ->and($pricing->schedule)->toEqual(PixelforgePricing::schedule());
    });

    it('converts the float text variance to whole percent without drift', function () {
        $data = PixelforgePricing::response();

        // Pairs, not array keys: PHP truncates float keys to integers.
        foreach ([[0.15, 15], [0.1, 10], [0.07, 7], [0.29, 29], [0, 0]] as [$fraction, $percent]) {
            $data['tokens']['text_token_variance'] = $fraction;

            expect(ResponseMapper::pricing($data)->schedule->textTokenVariancePercent)->toBe($percent);
        }
    });

    it('refuses a variance that is not a whole percentage', function () {
        $data = PixelforgePricing::response();
        $data['tokens']['text_token_variance'] = 0.155;

        ResponseMapper::pricing($data);
    })->throws(UnexpectedResponse::class);

    it('refuses an incomplete token table', function () {
        $data = PixelforgePricing::response();
        unset($data['tokens']['output']['high']);

        ResponseMapper::pricing($data);
    })->throws(UnexpectedResponse::class);

    it('refuses a response without rates', function () {
        $data = PixelforgePricing::response();
        unset($data['rates']);

        ResponseMapper::pricing($data);
    })->throws(UnexpectedResponse::class);
});

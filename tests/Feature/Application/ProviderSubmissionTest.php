<?php

use App\Application\Generation\GenerationNotFound;
use App\Application\Generation\ProviderSubmission;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\AuthenticationFailed;
use App\Application\Provider\Exceptions\ConcurrencyLimited;
use App\Application\Provider\Exceptions\ContentPolicyViolation;
use App\Application\Provider\Exceptions\DailyLimitExceeded;
use App\Application\Provider\Exceptions\IdempotencyKeyReused;
use App\Application\Provider\Exceptions\Overloaded;
use App\Application\Provider\Exceptions\RateLimited;
use App\Application\Provider\Exceptions\ValidationFailed;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\FakeImageProvider;

beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
});

function submission(): ProviderSubmission
{
    return app(ProviderSubmission::class);
}

function record(GenerationRecord $record): GenerationRecord
{
    return $record->fresh();
}

describe('submitting', function () {
    it('sends the recorded request with our id as the idempotency key and as metadata', function () {
        $pending = GenerationRecord::factory()->create([
            'prompt' => 'A red fox',
            'quality' => Quality::High,
            'size' => ImageSize::Landscape,
            'reference_images' => ['https://example.com/ref.png'],
        ]);

        submission()->submit($pending->id);

        expect($this->provider->created)->toHaveCount(1);

        ['request' => $request, 'key' => $key] = $this->provider->created[0];

        expect($key)->toBe($pending->id)
            ->and($request->prompt)->toBe('A red fox')
            ->and($request->quality)->toBe(Quality::High)
            ->and($request->size)->toBe(ImageSize::Landscape)
            ->and($request->referenceImages)->toBe(['https://example.com/ref.png'])
            ->and($request->metadata)->toBe(['generation_id' => $pending->id]);
    });

    it('stores Pixelforge\'s id and marks the generation queued', function () {
        $pending = GenerationRecord::factory()->create();

        submission()->submit($pending->id);

        expect(record($pending))
            ->status->toBe(GenerationStatus::Queued)
            ->provider_id->toBe('gen_test0001')
            ->charged_nanos->toBeNull();
    });

    it('does nothing when the generation was already handed over', function () {
        $queued = GenerationRecord::factory()->queued()->create();

        submission()->submit($queued->id);

        expect($this->provider->created)->toBe([]);
    });

    it('can be run again after a crash and still ends with one generation', function () {
        // First run: Pixelforge accepted it but we died before saving the answer.
        $pending = GenerationRecord::factory()->create();
        submission()->submit($pending->id);
        GenerationRecord::query()->whereKey($pending->id)->update(['status' => 'pending', 'provider_id' => null]);

        // Second run: the same key goes out again, and Pixelforge answers with the same generation.
        submission()->submit($pending->id);

        expect($this->provider->created)->toHaveCount(2)
            ->and(array_column($this->provider->created, 'key'))->toBe([$pending->id, $pending->id])
            ->and(record($pending))
            ->status->toBe(GenerationStatus::Queued)
            ->provider_id->toBe('gen_test0001');
    });

    it('keeps a later state when a webhook got there before the response', function () {
        $pending = GenerationRecord::factory()->create();

        // The "processing" webhook is handled while our POST is still in flight.
        $this->provider->whileCreating = fn () => app(GenerationRepository::class)->update(
            $pending->id,
            function (Generation $generation) {
                $generation->linkProvider('gen_test0001');
                $generation->markProcessing();
            },
        );

        submission()->submit($pending->id);

        expect(record($pending))
            ->status->toBe(GenerationStatus::Processing)
            ->provider_id->toBe('gen_test0001');
    });

    it('fails for a generation that does not exist', function () {
        expect(fn () => submission()->submit('0192a0a0-0000-7000-8000-00000000dead'))
            ->toThrow(GenerationNotFound::class);
    });
});

describe('when Pixelforge says try again later', function () {
    it('rethrows so the job can retry, and leaves the generation pending', function (Throwable $failure) {
        $pending = GenerationRecord::factory()->create();
        $this->provider->createFailure = $failure;

        expect(fn () => submission()->submit($pending->id))->toThrow($failure::class);

        expect(record($pending))
            ->status->toBe(GenerationStatus::Pending)
            ->provider_id->toBeNull()
            ->error_code->toBeNull();
    })->with([
        'overloaded' => [fn () => new Overloaded('busy', 2)],
        'rate limited' => [fn () => new RateLimited('slow down', 10)],
        'too many in flight' => [fn () => new ConcurrencyLimited('3 at a time', 5)],
    ]);
});

describe('when Pixelforge refuses for good', function () {
    it('fails the generation, free of charge, with a message for the user', function (Throwable $failure, string $code, string $messagePart) {
        $pending = GenerationRecord::factory()->create();
        $this->provider->createFailure = $failure;

        submission()->submit($pending->id);

        expect(record($pending))
            ->status->toBe(GenerationStatus::Failed)
            ->error_code->toBe($code)
            ->error_message->toContain($messagePart, 'You were not charged')
            ->provider_cost_nanos->toBe(0)
            ->charged_nanos->toBe(0)
            ->completed_at->not->toBeNull();
    })->with([
        'blocked prompt' => [fn () => new ContentPolicyViolation('no', 'content_policy_violation', 400), 'content_policy_violation', 'content policy'],
        'invalid request' => [fn () => new ValidationFailed('The prompt field is required.', ['prompt' => ['required']]), 'invalid_request', 'prompt field is required'],
        'daily limit' => [fn () => new DailyLimitExceeded('500 a day', 'daily_limit_exceeded', 429), 'provider_daily_limit', 'daily'],
    ]);

    it('hides our own configuration problems from the user but logs them', function (Throwable $failure) {
        Log::spy();
        $pending = GenerationRecord::factory()->create();
        $this->provider->createFailure = $failure;

        submission()->submit($pending->id);

        expect(record($pending))
            ->status->toBe(GenerationStatus::Failed)
            ->error_code->toBe('provider_error')
            ->error_message->not->toContain('key')
            ->charged_nanos->toBe(0);

        Log::shouldHaveReceived('error')->once();
    })->with([
        'bad API key' => [fn () => new AuthenticationFailed('Invalid API key', 'invalid_api_key', 401)],
        'reused idempotency key' => [fn () => new IdempotencyKeyReused('reused', 'idempotency_key_reused', 409)],
    ]);
});

describe('giving up', function () {
    it('fails a generation that never reached Pixelforge, free of charge', function () {
        $pending = GenerationRecord::factory()->create();

        submission()->giveUp($pending->id);

        expect(record($pending))
            ->status->toBe(GenerationStatus::Failed)
            ->error_code->toBe('provider_unavailable')
            ->charged_nanos->toBe(0);
    });

    it('never overrides a generation that already finished', function () {
        $done = GenerationRecord::factory()->succeeded()->create();

        submission()->giveUp($done->id);

        expect(record($done))
            ->status->toBe(GenerationStatus::Succeeded)
            ->charged_nanos->toBe(70_687_500)
            ->error_code->toBeNull();
    });
});

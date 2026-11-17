<?php

use App\Application\Ports\ImageProvider;
use App\Application\Provider\CreateGenerationRequest;
use App\Application\Provider\Exceptions\AuthenticationFailed;
use App\Application\Provider\Exceptions\ConcurrencyLimited;
use App\Application\Provider\Exceptions\ContentPolicyViolation;
use App\Application\Provider\Exceptions\DailyLimitExceeded;
use App\Application\Provider\Exceptions\IdempotencyKeyReused;
use App\Application\Provider\Exceptions\Overloaded;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\Exceptions\ProviderNotFound;
use App\Application\Provider\Exceptions\ProviderUnavailable;
use App\Application\Provider\Exceptions\RateLimited;
use App\Application\Provider\Exceptions\RetryableProviderException;
use App\Application\Provider\Exceptions\UnexpectedResponse;
use App\Application\Provider\Exceptions\ValidationFailed;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\PixelforgeApi;
use Tests\Fixtures\PixelforgePricing;
use Tests\Fixtures\PixelforgeUsage;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'pf_test_key',
    ]);
});

function provider(): ImageProvider
{
    return app(ImageProvider::class);
}

function aRequest(array $overrides = []): CreateGenerationRequest
{
    return new CreateGenerationRequest(...array_replace([
        'prompt' => 'A lighthouse at dusk',
        'quality' => Quality::Medium,
        'size' => ImageSize::Square,
    ], $overrides));
}

describe('createGeneration', function () {
    it('posts the request with the bearer key and idempotency key, and returns the queued generation', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::generation('queued'), 202)]);

        $generation = provider()->createGeneration(aRequest(), 'idem-123');

        expect($generation->id)->toBe('gen_test0001')
            ->and($generation->status)->toBe(GenerationStatus::Queued);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->header('Authorization') === ['Bearer pf_test_key']
            && $request->header('Idempotency-Key') === ['idem-123']
            && $request->data() === ['prompt' => 'A lighthouse at dusk', 'size' => '1024x1024', 'quality' => 'medium']);
    });

    it('sends reference images and metadata only when there are some', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::generation('queued'), 202)]);

        provider()->createGeneration(aRequest([
            'quality' => Quality::High,
            'size' => ImageSize::Landscape,
            'referenceImages' => ['https://example.com/a.png'],
            'metadata' => ['generation_id' => 'abc'],
        ]), 'idem-1');

        Http::assertSent(fn (Request $request) => $request->data() === [
            'prompt' => 'A lighthouse at dusk',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
            'metadata' => ['generation_id' => 'abc'],
        ]);
    });

    it('returns the existing generation when Pixelforge replays an idempotency key', function () {
        Http::fake(['pixelforge.test/*' => Http::response(
            PixelforgeApi::generation('processing'), 202, ['Idempotent-Replayed' => 'true'],
        )]);

        $generation = provider()->createGeneration(aRequest(), 'idem-123');

        expect($generation->status)->toBe(GenerationStatus::Processing);
    });
});

describe('getGeneration', function () {
    it('fetches a generation by its provider id', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::generation('succeeded'))]);

        $generation = provider()->getGeneration('gen_test0001');

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage->outputTokens)->toBe(1_100);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.test/v1/generations/gen_test0001');
    });

    it('url-encodes the id', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::generation())]);

        provider()->getGeneration('gen/../x');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/generations/gen%2F..%2Fx');
    });
});

describe('pricing', function () {
    it('reads the published rates and token tables', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgePricing::response())]);

        $pricing = provider()->pricing();

        expect($pricing->rates)->toEqual(PixelforgePricing::rates())
            ->and($pricing->schedule)->toEqual(PixelforgePricing::schedule());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/pricing');
    });
});

describe('errors', function () {
    it('maps each documented error to its own exception', function (int $status, array $body, array $headers, string $expected) {
        Http::fake(['pixelforge.test/*' => Http::response($body, $status, $headers)]);

        expect(fn () => provider()->createGeneration(aRequest(), 'idem'))->toThrow($expected);
    })->with([
        '401 invalid key' => [401, PixelforgeApi::error('authentication_error', 'invalid_api_key'), [], AuthenticationFailed::class],
        '400 content policy' => [400, PixelforgeApi::error('invalid_request_error', 'content_policy_violation'), [], ContentPolicyViolation::class],
        '422 validation' => [422, PixelforgeApi::error('invalid_request_error', 'validation_failed'), [], ValidationFailed::class],
        '404 not found' => [404, PixelforgeApi::error('invalid_request_error', 'not_found'), [], ProviderNotFound::class],
        '409 key reused' => [409, PixelforgeApi::error('idempotency_error', 'idempotency_key_reused'), [], IdempotencyKeyReused::class],
        '429 rate limit' => [429, PixelforgeApi::error('rate_limit_error', 'rate_limit_exceeded'), ['Retry-After' => '12'], RateLimited::class],
        '429 concurrency' => [429, PixelforgeApi::error('rate_limit_error', 'concurrency_limit_exceeded'), ['Retry-After' => '5'], ConcurrencyLimited::class],
        '429 daily limit' => [429, PixelforgeApi::error('rate_limit_error', 'daily_limit_exceeded'), [], DailyLimitExceeded::class],
        '503 overloaded' => [503, PixelforgeApi::error('api_error', 'overloaded'), ['Retry-After' => '2'], Overloaded::class],
        '500 internal' => [500, PixelforgeApi::error('api_error', 'internal_error'), [], ProviderUnavailable::class],
        '418 unmapped' => [418, PixelforgeApi::error('weird', 'teapot'), [], UnexpectedResponse::class],
    ]);

    it('marks exactly the self-healing failures as retryable', function (int $status, array $body, bool $retryable) {
        Http::fake(['pixelforge.test/*' => Http::response($body, $status)]);

        try {
            provider()->createGeneration(aRequest(), 'idem');
        } catch (ProviderException $e) {
            expect($e instanceof RetryableProviderException)->toBe($retryable);

            return;
        }

        $this->fail('Expected a ProviderException');
    })->with([
        'rate limit' => [429, PixelforgeApi::error('rate_limit_error', 'rate_limit_exceeded'), true],
        'concurrency' => [429, PixelforgeApi::error('rate_limit_error', 'concurrency_limit_exceeded'), true],
        'overloaded' => [503, PixelforgeApi::error('api_error', 'overloaded'), true],
        'server error' => [502, PixelforgeApi::error('api_error', 'bad_gateway'), true],
        'daily limit' => [429, PixelforgeApi::error('rate_limit_error', 'daily_limit_exceeded'), false],
        'content policy' => [400, PixelforgeApi::error('invalid_request_error', 'content_policy_violation'), false],
        'validation' => [422, PixelforgeApi::error('invalid_request_error', 'validation_failed'), false],
        'bad key' => [401, PixelforgeApi::error('authentication_error', 'invalid_api_key'), false],
    ]);

    it('exposes the provider code, status and message', function () {
        Http::fake(['pixelforge.test/*' => Http::response(
            PixelforgeApi::error('invalid_request_error', 'content_policy_violation', 'Prompt rejected.'), 400,
        )]);

        try {
            provider()->createGeneration(aRequest(), 'idem');
        } catch (ContentPolicyViolation $e) {
            expect($e->getMessage())->toBe('Prompt rejected.')
                ->and($e->providerCode)->toBe('content_policy_violation')
                ->and($e->httpStatus)->toBe(400);

            return;
        }

        $this->fail('Expected ContentPolicyViolation');
    });

    it('carries the per-parameter validation messages', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::error(
            'invalid_request_error', 'validation_failed', 'Invalid.',
            ['param' => 'quality', 'errors' => ['quality' => ['is invalid'], 'size' => ['is required', 'must be a string']]],
        ), 422)]);

        try {
            provider()->createGeneration(aRequest(), 'idem');
        } catch (ValidationFailed $e) {
            expect($e->errors)->toBe(['quality' => ['is invalid'], 'size' => ['is required', 'must be a string']]);

            return;
        }

        $this->fail('Expected ValidationFailed');
    });

    it('does not retry by itself', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::error('api_error', 'overloaded'), 503)]);

        expect(fn () => provider()->createGeneration(aRequest(), 'idem'))->toThrow(Overloaded::class);

        Http::assertSentCount(1);
    });
});

describe('Retry-After', function () {
    it('reads the number of seconds Pixelforge asks us to wait', function (?string $header, int $expected) {
        Http::fake(['pixelforge.test/*' => Http::response(
            PixelforgeApi::error('rate_limit_error', 'rate_limit_exceeded'), 429, $header === null ? [] : ['Retry-After' => $header],
        )]);

        try {
            provider()->createGeneration(aRequest(), 'idem');
        } catch (RateLimited $e) {
            expect($e->retryAfterSeconds)->toBe($expected);

            return;
        }

        $this->fail('Expected RateLimited');
    })->with([
        'seconds' => ['30', 30],
        'missing falls back to the default' => [null, 10],
        'an HTTP date is not understood, so the default' => ['Wed, 21 Oct 2026 07:28:00 GMT', 10],
        'zero is bumped to one second' => ['0', 1],
        'huge values are capped at an hour' => ['999999', 3600],
    ]);

    it('has sensible defaults per error type when the header is missing', function () {
        Http::fake(['pixelforge.test/*' => Http::sequence()
            ->push(PixelforgeApi::error('rate_limit_error', 'concurrency_limit_exceeded'), 429)
            ->push(PixelforgeApi::error('api_error', 'overloaded'), 503)
            ->push(PixelforgeApi::error('api_error', 'internal_error'), 500)]);

        $waits = [];
        foreach (range(1, 3) as $_) {
            try {
                provider()->createGeneration(aRequest(), 'idem');
            } catch (RetryableProviderException $e) {
                $waits[$e::class] = $e->retryAfterSeconds;
            }
        }

        expect($waits)->toBe([ConcurrencyLimited::class => 5, Overloaded::class => 2, ProviderUnavailable::class => 5]);
    });
});

describe('usage', function () {
    it('asks for the date range and reads what Pixelforge billed per day', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeUsage::response([
            '2026-11-01' => [3, '0.169080000'],
            '2026-11-02' => [0, '0.000000000'],
        ]), 200)]);

        $usage = provider()->usage('2026-11-01', '2026-11-02');

        expect($usage->days)->toHaveCount(2)
            ->and($usage->days[0]->date)->toBe('2026-11-01')
            ->and($usage->days[0]->generations)->toBe(3)
            ->and($usage->days[0]->cost->toNanos())->toBe(169_080_000)
            ->and($usage->days[1]->isEmpty())->toBeTrue()
            ->and($usage->totalGenerations)->toBe(3)
            ->and($usage->totalCost->toDecimal())->toBe('0.169080000')
            ->and($usage->forDate('2026-11-01')->generations)->toBe(3)
            ->and($usage->forDate('2026-12-01'))->toBeNull();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://pixelforge.test/v1/usage')
            && $request->data() === ['start_date' => '2026-11-01', 'end_date' => '2026-11-02']
            && $request->header('Authorization') === ['Bearer pf_test_key']);
    });

    it('keeps every digit of a cost', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeUsage::response(['2026-11-01' => [1, '0.000000001']]), 200)]);

        expect(provider()->usage('2026-11-01', '2026-11-01')->days[0]->cost->toNanos())->toBe(1);
    });

    it('rejects a report it cannot read instead of guessing', function (array $body) {
        Http::fake(['pixelforge.test/*' => Http::response($body, 200)]);

        expect(fn () => provider()->usage('2026-11-01', '2026-11-02'))->toThrow(UnexpectedResponse::class);
    })->with([
        'no data' => [['object' => 'usage', 'total' => ['generations' => 0, 'cost_usd' => '0.000000000']]],
        'no total' => [['object' => 'usage', 'data' => []]],
        'a cost with too many decimals' => [['data' => [['date' => '2026-11-01', 'generations' => 1, 'cost_usd' => '0.0000000001']], 'total' => ['generations' => 1, 'cost_usd' => '0.000000000']]],
        'a cost that is not a number' => [['data' => [['date' => '2026-11-01', 'generations' => 1, 'cost_usd' => 'free']], 'total' => ['generations' => 1, 'cost_usd' => '0.000000000']]],
        'a count that is not a number' => [['data' => [['date' => '2026-11-01', 'generations' => 'many', 'cost_usd' => '0.000000000']], 'total' => ['generations' => 1, 'cost_usd' => '0.000000000']]],
    ]);

    it('reports a date range Pixelforge refuses as a validation failure', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::error('invalid_request_error', 'validation_failed', 'Invalid parameters.', ['errors' => ['end_date' => ['The range is too long.']]]), 422)]);

        expect(fn () => provider()->usage('2026-01-01', '2026-12-31'))->toThrow(ValidationFailed::class);
    });
});

describe('configuration', function () {
    it('uses the configured fallback waits and cap instead of built-in numbers', function () {
        config([
            'services.pixelforge.retry_after.rate_limit' => 41,
            'services.pixelforge.retry_after.max' => 60,
        ]);

        Http::fake(['pixelforge.test/*' => Http::sequence()
            ->push(PixelforgeApi::error('rate_limit_error', 'rate_limit_exceeded'), 429)
            ->push(PixelforgeApi::error('rate_limit_error', 'rate_limit_exceeded'), 429, ['Retry-After' => '999'])]);

        $waits = [];
        foreach (range(1, 2) as $_) {
            try {
                provider()->createGeneration(aRequest(), 'idem');
            } catch (RateLimited $e) {
                $waits[] = $e->retryAfterSeconds;
            }
        }

        expect($waits)->toBe([41, 60]);
    });

    it('uses the configured timeouts', function () {
        config(['services.pixelforge.timeout' => 7, 'services.pixelforge.connect_timeout' => 3]);

        $options = null;
        Http::fake(function (Request $request, array $o) use (&$options) {
            $options = $o;

            return Http::response(PixelforgeApi::generation('queued'), 202);
        });

        provider()->createGeneration(aRequest(), 'idem');

        expect($options['timeout'])->toBe(7)
            ->and($options['connect_timeout'])->toBe(3);
    });

    it('refuses to start without an API key', function () {
        config(['services.pixelforge.api_key' => '']);

        expect(fn () => provider())->toThrow(RuntimeException::class, 'PIXELFORGE_API_KEY');
    });
});

describe('unreliable responses', function () {
    it('treats a connection failure as retryable, since the same idempotency key makes a retry safe', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        try {
            provider()->createGeneration(aRequest(), 'idem');
        } catch (ProviderUnavailable $e) {
            expect($e->retryAfterSeconds)->toBeGreaterThan(0)
                ->and($e->getMessage())->toContain('timed out')
                ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);

            return;
        }

        $this->fail('Expected ProviderUnavailable');
    });

    it('survives an HTML error page from a proxy', function () {
        Http::fake(['pixelforge.test/*' => Http::response('<html>Bad gateway</html>', 502)]);

        expect(fn () => provider()->createGeneration(aRequest(), 'idem'))->toThrow(ProviderUnavailable::class);
    });

    it('rejects a successful response that is not JSON', function () {
        Http::fake(['pixelforge.test/*' => Http::response('ok', 200)]);

        expect(fn () => provider()->getGeneration('gen_test0001'))->toThrow(UnexpectedResponse::class);
    });

    it('rejects a successful response with a status we do not know', function () {
        Http::fake(['pixelforge.test/*' => Http::response(PixelforgeApi::generation('queued', ['status' => 'teleporting']), 202)]);

        expect(fn () => provider()->createGeneration(aRequest(), 'idem'))->toThrow(UnexpectedResponse::class);
    });
});

it('refuses to build the client without an API key', function () {
    config(['services.pixelforge.api_key' => null]);

    app(ImageProvider::class);
})->throws(RuntimeException::class, 'PIXELFORGE_API_KEY');

<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

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
use App\Application\Provider\Exceptions\UnexpectedResponse;
use App\Application\Provider\Exceptions\ValidationFailed;
use App\Application\Provider\PricingSnapshot;
use App\Application\Provider\ProviderGeneration;
use App\Application\Provider\ProviderUsage;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the Pixelforge API. It never retries on its own: whether and when
 * to retry is the caller's decision (a queued job), guided by the typed
 * exceptions below. Every failure leaves here as a ProviderException.
 */
final class PixelforgeClient implements ImageProvider
{
    public function __construct(private readonly PixelforgeSettings $settings)
    {
    }

    public function createGeneration(CreateGenerationRequest $request, string $idempotencyKey): ProviderGeneration
    {
        $body = [
            'prompt' => $request->prompt,
            'size' => $request->size->value,
            'quality' => $request->quality->value,
        ];

        if ($request->referenceImages !== []) {
            $body['reference_images'] = $request->referenceImages;
        }

        if ($request->metadata !== []) {
            $body['metadata'] = $request->metadata;
        }

        $data = $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $body));

        return ResponseMapper::generation($data);
    }

    public function getGeneration(string $providerId): ProviderGeneration
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($providerId)));

        return ResponseMapper::generation($data);
    }

    public function pricing(): PricingSnapshot
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/pricing'));

        return ResponseMapper::pricing($data);
    }

    public function usage(string $startDate, string $endDate): ProviderUsage
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));

        return ResponseMapper::usageReport($data);
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function call(Closure $send): array
    {
        $http = Http::baseUrl(rtrim($this->settings->baseUrl, '/'))
            ->withToken($this->settings->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->timeoutSeconds)
            ->connectTimeout($this->settings->connectTimeoutSeconds);

        try {
            $response = $send($http);
        } catch (ConnectionException $e) {
            // We cannot know whether Pixelforge received the request. Retrying
            // with the same idempotency key is what makes that safe.
            throw new ProviderUnavailable(
                'Could not reach Pixelforge: '.$e->getMessage(),
                $this->settings->retryUnavailable,
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw $this->errorFrom($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new UnexpectedResponse('Pixelforge returned a body that is not JSON.', httpStatus: $response->status());
        }

        return $data;
    }

    private function errorFrom(Response $response): ProviderException
    {
        $status = $response->status();
        $error = $response->json('error');
        $code = is_array($error) ? ($error['code'] ?? null) : null;
        $message = is_array($error) && isset($error['message'])
            ? (string) $error['message']
            : "Pixelforge returned HTTP {$status}.";

        return match (true) {
            $status === 401 => new AuthenticationFailed($message, $code, $status),
            $status === 404 => new ProviderNotFound($message, $code, $status),
            $status === 409 => new IdempotencyKeyReused($message, $code, $status),
            $status === 400 && $code === 'content_policy_violation' => new ContentPolicyViolation($message, $code, $status),
            $status === 422 => new ValidationFailed($message, $this->validationErrors($error), $code, $status),
            $status === 429 && $code === 'daily_limit_exceeded' => new DailyLimitExceeded($message, $code, $status),
            $status === 429 && $code === 'concurrency_limit_exceeded' => new ConcurrencyLimited(
                $message, $this->retryAfter($response, $this->settings->retryConcurrency), $code, $status,
            ),
            $status === 429 => new RateLimited(
                $message, $this->retryAfter($response, $this->settings->retryRateLimit), $code, $status,
            ),
            $status === 503 || $code === 'overloaded' => new Overloaded(
                $message, $this->retryAfter($response, $this->settings->retryOverloaded), $code, $status,
            ),
            $status >= 500 => new ProviderUnavailable(
                $message, $this->retryAfter($response, $this->settings->retryUnavailable), $code, $status,
            ),
            default => new UnexpectedResponse($message, $code, $status),
        };
    }

    /** Retry-After in whole seconds, bounded; falls back to a default when absent or not a number. */
    private function retryAfter(Response $response, int $default): int
    {
        $header = $response->header('Retry-After');

        if (! is_numeric($header)) {
            return $default;
        }

        return max(1, min((int) $header, $this->settings->retryMax));
    }

    /**
     * @param  mixed  $error
     * @return array<string, list<string>>
     */
    private function validationErrors(mixed $error): array
    {
        $errors = is_array($error) ? ($error['errors'] ?? []) : [];

        return is_array($errors)
            ? array_map(fn ($messages) => array_values((array) $messages), $errors)
            : [];
    }
}
